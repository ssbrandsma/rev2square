#!/usr/bin/env python3
"""Rev2Square isolated analysis runner (trust-boundary executable).

One long-running process per tool container. It reads structured job
specifications from a read-only spool volume, executes exactly one
allowlisted tool argv shape per profile inside a private tmpfs workdir,
and writes bounded, validated output to a results volume.

Security properties (enforced here, verified by tests):
  * No network access is required or used (stdlib only, no sockets).
  * The tool binary never sees spool paths: input is copied into a
    private workdir first, and size + sha256 are re-verified.
  * No arbitrary commands: argv is built from (tool, profile) plus
    scalar params validated against allowlists and numeric ranges.
  * Complete process-tree termination: the tool runs in its own
    session (start_new_session) and timeout/cancel kills the
    process group, then reaps.
  * Bounded output: byte, file-count, depth, and evidence caps are
    enforced while collecting; over-limit jobs fail closed.
  * Atomic claim: results/<job>/ is created with mkdir (exclusive);
    the global concurrency lock is an flock'd file.

Usage:
  runner.py --tool objdump --spool /spool --results /results [--once]
  runner.py --dry-run --tool ghidra --profile headless \\
      --params-json '{"language":"ARM:LE:32:v8","base":134217728}' \\
      --workdir /tmp/w
"""

import argparse
import fcntl
import hashlib
import json
import os
import platform
import shutil
import signal
import subprocess
import sys
import time

SCHEMA = 1

# ---- Static profile allowlists -------------------------------------------
# PHP mirrors: ObjdumpAdapter::VERIFIED_MACHINES, GhidraAdapter language
# map, JadxAdapter/IlspyAdapter buildCommand, ExtractAdapter profiles.
# SpoolProtocolTest asserts the dry-run argv shapes stay in sync.

OBJDUMP_MODES = ("auto", "binary", "ihex", "srec")

# Verified Ghidra language ids (Ghidra 12.2 .ldefs, source-verified).
# Documented-but-unverified ids are NEVER accepted here: display-only
# candidates must not become executable.
GHIDRA_LANGUAGES = (
    "ARM:LE:32:v8",
    "ARM:LE:32:v8T",
    "x86:LE:32:default",
    "x86:LE:64:default",
    "AARCH64:LE:64:v8A",
)

JADX_THREADS_MIN = 1
JADX_THREADS_MAX = 4

EXTRACT_PROFILES = ("7zz-list", "7zz-extract", "unsquashfs-list",
                    "unsquashfs-extract", "ubireader-extract")

TOOLS = ("objdump", "ghidra", "jadx", "ilspy", "extract")

# Collection bounds (runner-side; PHP re-validates everything read).
MAX_EVIDENCE_BYTES = 512 * 1024
MAX_INLINE_FILE_BYTES = 32 * 1024
MAX_INLINE_TOTAL_BYTES = 256 * 1024
MAX_MANIFEST_FILES = 2000
MAX_WALK_DEPTH = 12
MAX_OUTPUT_BYTES = 8 * 1024 * 1024
MAX_STORED_FILES = 5
MAX_STORED_FILE_BYTES = 8 * 1024 * 1024

GLOBAL_LOCK_NAME = "global.lock"
POLL_INTERVAL = 1.0


def fail(message):
    raise ValueError(message)


def read_json(path, max_bytes=65536):
    size = os.path.getsize(path)
    if size > max_bytes:
        fail("json too large: %s" % path)
    with open(path, "r", encoding="utf-8") as fh:
        return json.load(fh)


def sha256_file(path):
    digest = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def confined_name(name):
    """A spool input name must be a plain basename, never a path."""
    if not isinstance(name, str) or name == "":
        fail("input name must be a non-empty string")
    if "/" in name or "\\" in name or name in (".", ".."):
        fail("input name must be a plain file name")
    if len(name) > 128:
        fail("input name too long")
    return name


def bounded_int(value, minimum, maximum, label):
    if isinstance(value, bool) or not isinstance(value, int):
        fail("%s must be an integer" % label)
    if value < minimum or value > maximum:
        fail("%s out of range" % label)
    return value


def load_machine_allowlist():
    """Objdump -m names this image build verified (baked at build)."""
    path = os.environ.get("OBJDUMP_MACHINES_FILE", "/opt/objdump/machines.txt")
    try:
        with open(path, "r", encoding="utf-8") as fh:
            return [ln.strip() for ln in fh if ln.strip()]
    except OSError:
        return ["i386", "i386:x86-64"]


def build_argv(tool, profile, params, workdir, input_path):
    """Build the exact tool argv. Raises ValueError on any rejection."""
    if tool == "objdump":
        return _objdump_argv(profile, params, input_path)
    if tool == "ghidra":
        return _ghidra_argv(profile, params, workdir, input_path)
    if tool == "jadx":
        return _jadx_argv(profile, params, workdir, input_path)
    if tool == "ilspy":
        return _ilspy_argv(profile, params, workdir, input_path)
    if tool == "extract":
        return _extract_argv(profile, params, workdir, input_path)
    fail("unknown tool: %r" % (tool,))


def _tool_path(env_name, default):
    path = os.environ.get(env_name, default)
    if not os.path.isfile(path) or not os.access(path, os.X_OK):
        fail("tool binary missing or not executable: %s" % path)
    return path


def _objdump_argv(mode, params, input_path):
    if mode not in OBJDUMP_MODES:
        fail("unknown objdump profile: %r" % (mode,))
    binary = _tool_path("OBJDUMP_BIN", "/usr/bin/objdump")
    if mode == "auto":
        return [binary, "-d", "-M", "intel", "-w", input_path]
    machine = params.get("machine", "")
    if machine not in load_machine_allowlist():
        fail("objdump machine not allowlisted: %r" % (machine,))
    if mode == "binary":
        vma = bounded_int(params.get("vma"), 0, 2 ** 64 - 1, "vma")
        return [binary, "-D", "-M", "intel", "-w", "-b", "binary",
                "-m", machine, "--adjust-vma=%d" % vma, input_path]
    # ihex / srec record formats carry their own addresses.
    return [binary, "-D", "-M", "intel", "-w", "-b", mode,
            "-m", machine, input_path]


def _ghidra_argv(profile, params, workdir, input_path):
    if profile != "headless":
        fail("unknown ghidra profile: %r" % (profile,))
    binary = _tool_path("GHIDRA_HEADLESS",
                        "/opt/ghidra/support/analyzeHeadless")
    language = params.get("language", "")
    if language not in GHIDRA_LANGUAGES:
        fail("ghidra language not allowlisted: %r" % (language,))
    base = bounded_int(params.get("base"), 0, 2 ** 64 - 1, "base")
    entry = params.get("entry")
    entry_arg = ("0x%x" % bounded_int(entry, 0, 2 ** 64 - 1, "entry")
                 if entry is not None else "none")
    project = os.path.join(workdir, "ghidra-project")
    os.makedirs(project, exist_ok=True)
    output = os.path.join(workdir, "evidence.json")
    # analyzeHeadless takes TWO positionals (location + name); the
    # name is fixed since each job gets a fresh project directory.
    return [binary, project, "analysis", "-import", input_path,
            "-overwrite",
            "-processor", language, "-cspec", "default",
            "-postScript", "ExportEvidence.java", output,
            "0x%x" % base, entry_arg, "-deleteProject"]


def tool_env(tool, workdir):
    """Minimal subprocess environment. Ghidra additionally scopes
    every JVM-writable path per job through the headless launcher's
    documented GHIDRA_HEADLESS_JAVA_OPTIONS hook (user.home, the JVM
    temp dir, and the application cache dir); all three live under
    the job workdir so cleanup removes them. The JDK probe itself
    (LaunchSupport -save) runs without these flags and uses the
    image's shared home, which stays writable tmpfs."""
    env = {"PATH": "/usr/bin:/bin:/opt/jadx/bin:/opt/ilspy",
           "LC_ALL": "C",
           "JAVA_HOME": os.environ.get("JAVA_HOME", ""),
           "DOTNET_CLI_TELEMETRY_OPTOUT": "1",
           "DOTNET_NOLOGO": "1"}
    if tool == "ghidra":
        if any(c.isspace() for c in workdir):
            fail("ghidra workdir must not contain whitespace")
        sub = {name: os.path.join(workdir, name)
               for name in ("ghidra-home", "ghidra-tmp", "ghidra-cache")}
        for d in sub.values():
            os.makedirs(d, exist_ok=True)
        env["GHIDRA_HEADLESS_JAVA_OPTIONS"] = (
            "-Duser.home=%s -Djava.io.tmpdir=%s -Dapplication.cachedir=%s"
            % (sub["ghidra-home"], sub["ghidra-tmp"], sub["ghidra-cache"]))
    return env


def _jadx_argv(profile, params, workdir, input_path):
    if profile != "apk":
        fail("unknown jadx profile: %r" % (profile,))
    binary = _tool_path("JADX_BIN", "/opt/jadx/bin/jadx")
    threads = bounded_int(params.get("threads", 2),
                          JADX_THREADS_MIN, JADX_THREADS_MAX, "threads")
    outdir = os.path.join(workdir, "jadx-out")
    return [binary, "--no-src-save-cache", "-j", str(threads),
            "-d", outdir, input_path]


def _ilspy_argv(profile, params, workdir, input_path):
    if profile != "assembly":
        fail("unknown ilspy profile: %r" % (profile,))
    binary = _tool_path("ILSPY_BIN", "/opt/ilspy/ilspycmd")
    outdir = os.path.join(workdir, "ilspy-out")
    return [binary, "-p", "-o", outdir, input_path]


def _extract_argv(profile, params, workdir, input_path):
    if profile not in EXTRACT_PROFILES:
        fail("unknown extract profile: %r" % (profile,))
    outdir = os.path.join(workdir, "extract-out")
    os.makedirs(outdir, exist_ok=True)
    if profile == "7zz-list":
        binary = _tool_path("SEVENZZ_BIN", "/opt/7zz/7zz")
        return [binary, "l", "-slt", "-ba", input_path]
    if profile == "7zz-extract":
        binary = _tool_path("SEVENZZ_BIN", "/opt/7zz/7zz")
        return [binary, "x", "-y", "-o" + outdir, input_path]
    if profile.startswith("unsquashfs"):
        binary = _tool_path("UNSQUASHFS_BIN", "/usr/bin/unsquashfs")
        if profile == "unsquashfs-list":
            return [binary, "-ll", "-d", outdir, input_path]
        return [binary, "-d", outdir, "-f", input_path]
    # ubireader-extract
    binary = _tool_path("UBIREADER_BIN", "/opt/ubireader/ubireader_extract_files")
    return [binary, "-o", outdir, input_path]


# ---- Job execution ---------------------------------------------------------

class Runner:
    def __init__(self, tool, spool, results):
        self.tool = tool
        self.spool = spool
        self.results = results

    def pending_jobs(self):
        try:
            names = sorted(os.listdir(self.spool))
        except OSError:
            return []
        out = []
        for name in names:
            if name.startswith(".") or len(name) > 64:
                continue
            jobdir = os.path.join(self.spool, name)
            claimdir = os.path.join(self.results, name)
            if (os.path.isdir(jobdir)
                    and os.path.isfile(os.path.join(jobdir, "job.json"))
                    and not os.path.exists(claimdir)):
                out.append(name)
        return out

    def run_once(self):
        for name in self.pending_jobs():
            try:
                job = read_json(os.path.join(self.spool, name, "job.json"))
            except (OSError, ValueError):
                continue
            if not isinstance(job, dict) or job.get("schema") != SCHEMA:
                continue
            if job.get("tool") != self.tool:
                continue
            kind = job.get("kind", "analyze")
            if kind == "probe":
                self.answer_probe(name)
            elif kind == "analyze":
                self.run_job(name, job)
            # Unknown kinds are ignored: a newer coordinator must not
            # trick an older runner into executing something new.

    def answer_probe(self, name):
        claimdir = os.path.join(self.results, name)
        try:
            os.mkdir(claimdir)
        except FileExistsError:
            return
        answer = {
            "schema": SCHEMA,
            "tool": self.tool,
            "tool_version": self.tool_version(),
            "runner_arch": platform.machine(),
            "image_digest": os.environ.get("IMAGE_DIGEST", "unknown"),
            "tool_image": os.environ.get("TOOL_IMAGE", "unknown"),
            "answered_at": int(time.time()),
        }
        if self.tool == "objdump":
            answer["architectures"] = self.objdump_architectures()
        with open(os.path.join(claimdir, "status.json"), "w",
                  encoding="utf-8") as fh:
            json.dump(answer, fh)
        os.chmod(os.path.join(claimdir, "status.json"), 0o644)

    def tool_version(self):
        flag = {"ghidra": ["-version"]}.get(self.tool, ["--version"])
        path = {"objdump": os.environ.get("OBJDUMP_BIN", "/usr/bin/objdump"),
                "ghidra": os.environ.get("GHIDRA_HEADLESS",
                                         "/opt/ghidra/support/analyzeHeadless"),
                "jadx": os.environ.get("JADX_BIN", "/opt/jadx/bin/jadx"),
                "ilspy": os.environ.get("ILSPY_BIN", "/opt/ilspy/ilspycmd"),
                "extract": os.environ.get("SEVENZZ_BIN",
                                          "/opt/7zz/7zz")}[self.tool]
        try:
            proc = subprocess.run(
                [path] + flag, stdin=subprocess.DEVNULL,
                stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=30)
        except (OSError, subprocess.SubprocessError):
            return None
        if proc.returncode != 0:
            return None
        return proc.stdout[:512].decode("utf-8", "replace").strip()

    def objdump_architectures(self):
        """Parse `objdump -i` architecture rows (mirrors the PHP parser:
        shallow-indented leading tokens without '-' or ':')."""
        import re
        path = os.environ.get("OBJDUMP_BIN", "/usr/bin/objdump")
        try:
            proc = subprocess.run(
                [path, "-i"], stdin=subprocess.DEVNULL,
                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                timeout=30)
        except (OSError, subprocess.SubprocessError):
            return []
        if proc.returncode != 0:
            return []
        archs = []
        for line in proc.stdout.decode("utf-8", "replace").split("\n"):
            m = re.match(r"^\s{1,8}([A-Za-z0-9_+-]+)(?:\s|$)", line)
            if not m or "-" in m.group(1) or ":" in m.group(1):
                continue
            if m.group(1) not in archs:
                archs.append(m.group(1))
        return archs[:64]

    def run_job(self, name, job):
        claimdir = os.path.join(self.results, name)
        try:
            os.mkdir(claimdir)
        except FileExistsError:
            return  # another runner claimed it
        status_path = os.path.join(claimdir, "status.json")
        self._write_status(status_path, "claimed", {})

        lock_path = os.path.join(self.results, GLOBAL_LOCK_NAME)
        try:
            lockfh = open(lock_path, "w")
        except OSError:
            self._write_status(status_path, "failed",
                               {"error": "cannot open global lock"})
            return
        try:
            fcntl.flock(lockfh.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            # Another heavy job is running server-wide: release the
            # claim so a later poll retries (claim removal is safe:
            # only status.json exists so far).
            lockfh.close()
            os.unlink(status_path)
            os.rmdir(claimdir)
            return
        try:
            self._execute(name, job, claimdir, status_path)
        finally:
            fcntl.flock(lockfh.fileno(), fcntl.LOCK_UN)
            lockfh.close()

    def _execute(self, name, job, claimdir, status_path):
        started = time.time()
        workdir = os.path.join(
            os.environ.get("RUNNER_WORKDIR", "/work"), name)
        try:
            profile = job.get("profile", "")
            params = job.get("params", {})
            if not isinstance(params, dict):
                fail("params must be an object")
            limits = job.get("limits", {})
            wall = bounded_int(limits.get("wall_seconds", 60), 1, 3600,
                               "wall_seconds")

            input_name = confined_name(job.get("input", ""))
            src = os.path.join(self.spool, name, input_name)
            if not os.path.isfile(src):
                fail("spool input missing")

            os.makedirs(workdir, exist_ok=True)
            staged = os.path.join(workdir, input_name)
            expected_size = bounded_int(job.get("input_size", -1), 0,
                                        256 * 1024 * 1024, "input_size")
            expected_sha = job.get("input_sha256", "")
            if (not isinstance(expected_sha, str)
                    or len(expected_sha) != 64):
                fail("input_sha256 must be a hex sha256")
            with open(src, "rb") as fin, open(staged, "wb") as fout:
                shutil.copyfileobj(fin, fout, 1024 * 1024)
            if (os.path.getsize(staged) != expected_size
                    or sha256_file(staged) != expected_sha):
                fail("staged input failed size/sha256 verification")
            os.chmod(staged, 0o400)

            if self._cancelled(claimdir):
                self._write_status(status_path, "cancelled", {})
                return

            argv = build_argv(self.tool, profile, params, workdir, staged)
            self._write_status(status_path, "running",
                               {"profile": profile,
                                "started_at": int(started)})
            out_path = os.path.join(workdir, "tool.out")
            if self.tool == "objdump":
                result = self._run_capture(
                    argv, workdir, out_path, wall, claimdir)
            else:
                result = self._run_quiet(
                    argv, workdir, out_path, wall, claimdir)
            if result == "cancelled":
                self._write_status(status_path, "cancelled",
                                   {"elapsed_ms": self._elapsed(started)})
                return
            if result == "timeout":
                self._write_status(status_path, "timeout",
                                   {"elapsed_ms": self._elapsed(started),
                                    "wall_seconds": wall})
                return
            exit_code = result

            evidence, extra = self._collect(
                workdir, claimdir, profile, params, exit_code)
            self._write_status(status_path, "done", {
                "elapsed_ms": self._elapsed(started),
                "exit_code": exit_code,
                "evidence_bytes": extra["evidence_bytes"],
                "files": extra["files"],
                "truncated": extra["truncated"],
            } | ({"warning": extra["warning"]} if extra.get("warning") else {}))
        except ValueError as exc:
            self._write_status(status_path, "failed",
                               {"elapsed_ms": self._elapsed(started),
                                "error": str(exc)[:500]})
        except OSError as exc:
            self._write_status(status_path, "failed",
                               {"elapsed_ms": self._elapsed(started),
                                "error": "io: %s" % str(exc)[:400]})
        finally:
            shutil.rmtree(workdir, ignore_errors=True)

    @staticmethod
    def _elapsed(started):
        return int((time.time() - started) * 1000)

    @staticmethod
    def _cancelled(claimdir):
        return os.path.exists(os.path.join(claimdir, "cancel"))

    @staticmethod
    def _write_status(path, state, extra):
        tmp = path + ".tmp"
        with open(tmp, "w", encoding="utf-8") as fh:
            json.dump({"schema": SCHEMA, "state": state} | extra, fh)
        os.replace(tmp, path)
        os.chmod(path, 0o644)

    def _popen(self, argv, workdir):
        return subprocess.Popen(
            argv, stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            cwd=workdir, start_new_session=True,
            env=tool_env(self.tool, workdir))

    def _reap_tree(self, proc):
        try:
            os.killpg(proc.pid, signal.SIGTERM)
        except (ProcessLookupError, PermissionError):
            pass
        try:
            proc.wait(timeout=5)
            return
        except subprocess.TimeoutExpired:
            pass
        try:
            os.killpg(proc.pid, signal.SIGKILL)
        except (ProcessLookupError, PermissionError):
            pass
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            pass

    def _run_capture(self, argv, workdir, out_path, wall, claimdir):
        """Run while streaming stdout to a capped file (objdump text)."""
        proc = self._popen(argv, workdir)
        deadline = time.time() + wall
        written = 0
        capped = False
        try:
            with open(out_path, "wb") as fout:
                while True:
                    if self._cancelled(claimdir):
                        self._reap_tree(proc)
                        return "cancelled"
                    remaining = deadline - time.time()
                    if remaining <= 0:
                        self._reap_tree(proc)
                        return "timeout"
                    chunk = None
                    # Block briefly so cancel/timeout stay responsive.
                    import select
                    ready, _, _ = select.select(
                        [proc.stdout], [], [], min(0.5, remaining))
                    if ready:
                        chunk = os.read(proc.stdout.fileno(), 65536)
                    if chunk:
                        if written + len(chunk) > MAX_OUTPUT_BYTES:
                            chunk = chunk[:MAX_OUTPUT_BYTES - written]
                            capped = True
                        fout.write(chunk)
                        written += len(chunk)
                        if capped:
                            self._reap_tree(proc)
                            break
                    elif proc.poll() is not None:
                        # Drain anything left, then stop.
                        rest = proc.stdout.read()
                        if rest:
                            room = MAX_OUTPUT_BYTES - written
                            fout.write(rest[:room])
                        break
            return proc.returncode
        finally:
            if proc.poll() is None:
                self._reap_tree(proc)

    def _run_quiet(self, argv, workdir, out_path, wall, claimdir):
        """Run with bounded combined-output capture (structured tools)."""
        proc = self._popen(argv, workdir)
        deadline = time.time() + wall
        try:
            while True:
                if self._cancelled(claimdir):
                    self._reap_tree(proc)
                    return "cancelled"
                try:
                    proc.wait(timeout=0.5)
                    break
                except subprocess.TimeoutExpired:
                    if time.time() >= deadline:
                        self._reap_tree(proc)
                        return "timeout"
            data = proc.stdout.read() if proc.stdout else b""
            with open(out_path, "wb") as fout:
                fout.write(data[:MAX_OUTPUT_BYTES])
            return proc.returncode
        finally:
            if proc.poll() is None:
                self._reap_tree(proc)

    def _collect(self, workdir, claimdir, profile, params, exit_code):
        """Copy bounded output into the claim dir. Returns (None, info)."""
        info = {"evidence_bytes": 0, "files": 0, "truncated": False}
        if self.tool == "objdump":
            src = os.path.join(workdir, "tool.out")
            size = os.path.getsize(src) if os.path.isfile(src) else 0
            if size >= MAX_OUTPUT_BYTES:
                info["truncated"] = True
            dst = os.path.join(claimdir, "tool.out")
            if os.path.isfile(src):
                shutil.copyfile(src, dst)
                os.chmod(dst, 0o644)
            evidence = {"schema": SCHEMA, "tool": self.tool,
                        "exit_code": exit_code,
                        "output_file": "tool.out",
                        "output_truncated": info["truncated"]}
            self._write_evidence(claimdir, evidence)
            info["evidence_bytes"] = os.path.getsize(
                os.path.join(claimdir, "evidence.json"))
            info["files"] = 1
            return None, info

        if self.tool == "ghidra":
            src = os.path.join(workdir, "evidence.json")
            if not os.path.isfile(src):
                fail("ghidra produced no evidence.json (exit %d)"
                     % exit_code)
            size = os.path.getsize(src)
            if size > MAX_EVIDENCE_BYTES:
                fail("ghidra evidence exceeds the byte cap")
            evidence = read_json(src, MAX_EVIDENCE_BYTES)
            if not isinstance(evidence, dict):
                fail("ghidra evidence is not a JSON object")
            self._write_evidence(claimdir, evidence)
            info["evidence_bytes"] = size
            info["files"] = 1
            return None, info

        # Tree-producing tools: jadx, ilspy, extract.
        outdir = {"jadx": os.path.join(workdir, "jadx-out"),
                  "ilspy": os.path.join(workdir, "ilspy-out"),
                  "extract": os.path.join(workdir, "extract-out")}[self.tool]
        manifest, inline, truncated = self._collect_tree(outdir)
        evidence = {"schema": SCHEMA, "tool": self.tool,
                    "exit_code": exit_code, "profile": profile,
                    "files": manifest, "inline": inline,
                    "truncated": truncated}
        if self.tool == "extract":
            evidence["stored_files"] = self._store_candidates(
                outdir, manifest, claimdir)
        self._write_evidence(claimdir, evidence)
        info["evidence_bytes"] = os.path.getsize(
            os.path.join(claimdir, "evidence.json"))
        info["files"] = len(manifest)
        info["truncated"] = truncated
        return None, info

    def _collect_tree(self, outdir):
        manifest = []
        inline = {}
        inline_total = 0
        truncated = False
        if not os.path.isdir(outdir):
            return manifest, inline, True
        for root, dirs, files in os.walk(outdir):
            depth = root[len(outdir):].count(os.sep)
            if depth > MAX_WALK_DEPTH:
                dirs[:] = []
                truncated = True
                continue
            # Never follow or record symlinks: escapes hide in links.
            dirs[:] = [d for d in sorted(dirs)
                       if not os.path.islink(os.path.join(root, d))]
            for name in sorted(files):
                if len(manifest) >= MAX_MANIFEST_FILES:
                    return manifest, inline, True
                full = os.path.join(root, name)
                if os.path.islink(full) or not os.path.isfile(full):
                    truncated = True
                    continue
                rel = os.path.relpath(full, outdir)
                try:
                    size = os.path.getsize(full)
                except OSError:
                    truncated = True
                    continue
                manifest.append({"path": rel, "size": size,
                                 "sha256": sha256_file(full)})
                if (size <= MAX_INLINE_FILE_BYTES
                        and inline_total + size <= MAX_INLINE_TOTAL_BYTES):
                    try:
                        with open(full, "rb") as fh:
                            data = fh.read()
                        inline[rel] = data.decode("utf-8")
                        inline_total += size
                    except (OSError, UnicodeDecodeError):
                        # Binary members stay manifest-only.
                        pass
        return manifest, inline, truncated

    def _store_candidates(self, outdir, manifest, claimdir):
        """Copy the largest extracted files (bounded count/size) into
        the claim for child-artifact promotion. Paths are re-derived
        from the validated manifest; symlinks are never followed."""
        store = os.path.join(claimdir, "files")
        os.makedirs(store, exist_ok=True)
        cands = sorted(
            (m for m in manifest
             if m["size"] > 0 and m["size"] <= MAX_STORED_FILE_BYTES),
            key=lambda m: m["size"], reverse=True)[:MAX_STORED_FILES]
        stored = []
        for cand in cands:
            if "/" in cand["sha256"] or len(cand["sha256"]) != 64:
                continue
            src = os.path.join(outdir, cand["path"])
            if (os.path.islink(src) or not os.path.isfile(src)
                    or os.path.getsize(src) != cand["size"]):
                continue
            # Re-hash at copy time: the bytes stored are the bytes named.
            digest = hashlib.sha256()
            dst = os.path.join(store, cand["sha256"] + ".bin")
            try:
                with open(src, "rb") as fin, open(dst, "wb") as fout:
                    for chunk in iter(lambda: fin.read(1024 * 1024), b""):
                        digest.update(chunk)
                        fout.write(chunk)
            except OSError:
                continue
            if digest.hexdigest() != cand["sha256"]:
                try:
                    os.unlink(dst)
                except OSError:
                    pass
                continue
            os.chmod(dst, 0o644)
            stored.append({"path": cand["path"], "size": cand["size"],
                           "sha256": cand["sha256"]})
        return stored

    def _write_evidence(self, claimdir, evidence):
        path = os.path.join(claimdir, "evidence.json")
        tmp = path + ".tmp"
        with open(tmp, "w", encoding="utf-8") as fh:
            json.dump(evidence, fh)
        if os.path.getsize(tmp) > MAX_EVIDENCE_BYTES:
            os.unlink(tmp)
            fail("evidence exceeds the byte cap")
        os.replace(tmp, path)
        os.chmod(path, 0o644)


def main(argv):
    parser = argparse.ArgumentParser()
    parser.add_argument("--tool", choices=TOOLS)
    parser.add_argument("--spool", default="/spool")
    parser.add_argument("--results", default="/results")
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--profile", default="")
    parser.add_argument("--params-json", default="{}")
    parser.add_argument("--workdir", default="/tmp/runner-dry-run")
    args = parser.parse_args(argv)

    if args.dry_run:
        if not args.tool:
            parser.error("--dry-run requires --tool")
        params = json.loads(args.params_json)
        argv_out = build_argv(args.tool, args.profile, params,
                              args.workdir,
                              os.path.join(args.workdir, "input.bin"))
        json.dump(argv_out, sys.stdout)
        sys.stdout.write("\n")
        return 0

    if not args.tool:
        parser.error("--tool is required")
    runner = Runner(args.tool, args.spool, args.results)
    if args.once:
        runner.run_once()
        return 0
    while True:
        runner.run_once()
        time.sleep(POLL_INTERVAL)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
