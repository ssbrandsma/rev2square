#!/usr/bin/env python3
"""Isolated-runner protocol tests (stdlib unittest, no pytest needed).

Two tiers, kept distinct:

* RunnerCase: STUB/static tests. Verifies docker/analysis/runner.py
  against stub TOOL binaries: exact argv shapes per profile,
  claim/lock semantics, timeout + cancel with complete
  process-tree termination, input verification, output caps, and
  symlink-escape rejection. Arch-independent: safe to run on any
  machine with python3 (CIs, x86 laptops). A stub green never
  proves a real tool accepts the argv it was fed.
* RealJadxCase: REAL-EXECUTABLE tests. Runs the actual pinned
  jadx 1.5.6 binary (plus a JVM) when REAL_JADX_BIN points at it;
  skipped otherwise. This tier guards the --no-src-save-cache
  incident class: flags the stubs accept but the real tool
  rejects. Native ARM64 qualification of the REAL tools happens
  on the owner server (see delivery doc); on x86 run with a
  matching-arch JRE on PATH.
* RealGhidraCase: REAL-EXECUTABLE tests for the pinned Ghidra
  12.2 probe path (usage-run execution proof + properties
  version) when REAL_GHIDRA_HEADLESS points at it; skipped
  otherwise. Guards the analyzeHeadless -version incident: the
  usage-only launcher behavior the stubs cannot reveal.

Run:  cd docker/analysis && python3 -m unittest discover -s tests -v
Real: cd docker/analysis && REAL_JADX_BIN=/path/to/jadx \
        python3 -m unittest tests.test_runner.RealJadxCase -v
      cd docker/analysis && REAL_GHIDRA_HEADLESS=/path/to/analyzeHeadless \
        python3 -m unittest tests.test_runner.RealGhidraCase -v
"""

import hashlib
import json
import os
import shutil
import signal
import struct
import subprocess
import sys
import tempfile
import time
import unittest
import zlib

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import runner


OBJDUMP_I = """\
GNU objdump (GNU Binutils) 2.40
BFD header file version (GNU Binutils) 2.40
Supported architectures:
  i386
  iamcu
  arm
  aarch64
  mips
Supported targets:
  elf64-x86-64
  elf32-i386
"""


class RunnerCase(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="runner-test-")
        self.spool = os.path.join(self.tmp, "spool")
        self.results = os.path.join(self.tmp, "results")
        self.work = os.path.join(self.tmp, "work")
        self.bindir = os.path.join(self.tmp, "bin")
        for d in (self.spool, self.results, self.work, self.bindir):
            os.makedirs(d)
        self.old_env = dict(os.environ)
        os.environ["RUNNER_WORKDIR"] = self.work
        os.environ["IMAGE_DIGEST"] = "test-digest"
        os.environ["TOOL_IMAGE"] = "test-image"

    def tearDown(self):
        os.environ.clear()
        os.environ.update(self.old_env)
        shutil.rmtree(self.tmp, ignore_errors=True)

    # -- helpers ------------------------------------------------------

    def stub(self, name, body):
        path = os.path.join(self.bindir, name)
        with open(path, "w") as fh:
            fh.write("#!/bin/sh\n" + body)
        os.chmod(path, 0o755)
        return path

    def submit(self, tool, profile="auto", params=None, payload=b"input-bytes",
               name="job1", wall=10, kind="analyze"):
        jobdir = os.path.join(self.spool, name)
        os.makedirs(jobdir)
        with open(os.path.join(jobdir, "input.bin"), "wb") as fh:
            fh.write(payload)
        job = {"schema": 1, "tool": tool, "kind": kind, "profile": profile,
               "params": params or {}, "input": "input.bin",
               "input_size": len(payload),
               "input_sha256": hashlib.sha256(payload).hexdigest(),
               "limits": {"wall_seconds": wall}}
        with open(os.path.join(jobdir, "job.json"), "w") as fh:
            json.dump(job, fh)
        return name

    def claim(self, name):
        with open(os.path.join(self.results, name, "status.json")) as fh:
            return json.load(fh)

    def run_once(self, tool):
        runner.Runner(tool, self.spool, self.results).run_once()

    # -- dry-run argv conformance --------------------------------------
    # These literals mirror the PHP buildCommand shapes
    # (Objdump/Ghidra/Jadx/Ilspy/ExtractAdapter); drift fails here.

    def dry(self, tool, profile, params):
        workdir = os.path.join(self.tmp, "dry")
        os.makedirs(workdir, exist_ok=True)
        argv = runner.build_argv(tool, profile, params, workdir,
                                os.path.join(workdir, "input.bin"))
        # Normalize the tmp workdir so literals stay readable.
        return [a.replace(workdir, "/w") for a in argv]

    def test_objdump_argv(self):
        objdump = self.stub("objdump", "exit 0\n")
        os.environ["OBJDUMP_BIN"] = objdump
        self.assertEqual(
            self.dry("objdump", "auto", {}),
            [objdump, "-d", "-M", "intel", "-w", "/w/input.bin"])
        with open(os.path.join(self.tmp, "m.txt"), "w") as fh:
            fh.write("i386\ni386:x86-64\n")
        os.environ["OBJDUMP_MACHINES_FILE"] = os.path.join(self.tmp, "m.txt")
        self.assertEqual(
            self.dry("objdump", "binary",
                     {"machine": "i386:x86-64", "vma": 4194304}),
            [objdump, "-D", "-M", "intel", "-w", "-b", "binary",
             "-m", "i386:x86-64", "--adjust-vma=4194304", "/w/input.bin"])
        self.assertEqual(
            self.dry("objdump", "srec", {"machine": "i386"}),
            [objdump, "-D", "-M", "intel", "-w", "-b", "srec",
             "-m", "i386", "/w/input.bin"])
        with self.assertRaises(ValueError):
            self.dry("objdump", "binary", {"machine": "arm", "vma": 0})
        with self.assertRaises(ValueError):
            self.dry("objdump", "binary",
                     {"machine": "i386", "vma": 2 ** 64})
        with self.assertRaises(ValueError):
            self.dry("objdump", "nope", {})

    def test_ghidra_argv(self):
        os.environ["GHIDRA_HEADLESS"] = self.stub("aheadless", "exit 0\n")
        argv = self.dry("ghidra", "headless",
                       {"language": "ARM:LE:32:v8", "base": 134217728,
                        "entry": None})
        self.assertEqual(argv[1:5],
                         ["/w/ghidra-project", "analysis",
                          "-import", "/w/input.bin"])
        self.assertTrue(argv[0].endswith("/aheadless"))
        self.assertIn("ARM:LE:32:v8", argv)
        self.assertIn("ExportEvidence.java", argv)
        self.assertIn("0x8000000", argv)
        # Documented-but-unverified ids are refused.
        with self.assertRaises(ValueError):
            self.dry("ghidra", "headless",
                     {"language": "MIPS:LE:32:default", "base": 0})
        with self.assertRaises(ValueError):
            self.dry("ghidra", "headless",
                     {"language": "AVR:LE:16:default", "base": 0})
        with self.assertRaises(ValueError):
            self.dry("ghidra", "headless",
                     {"language": "ARM:LE:32:v8;evil", "base": 0})

    def test_ghidra_tool_env_scopes_jvm_paths_per_job(self):
        env = runner.tool_env("ghidra", self.work)
        opts = env["GHIDRA_HEADLESS_JAVA_OPTIONS"]
        for flag in ("-Duser.home=", "-Djava.io.tmpdir=",
                     "-Dapplication.cachedir="):
            self.assertIn(flag + self.work, opts)
        for name in ("ghidra-home", "ghidra-tmp", "ghidra-cache"):
            self.assertTrue(os.path.isdir(os.path.join(self.work, name)))
        # The launcher word-splits this string: no value may contain
        # whitespace.
        for part in opts.split(" "):
            self.assertTrue(part.startswith("-D") and " " not in part)
        # Other tools keep the minimal env without JVM scoping.
        self.assertNotIn("GHIDRA_HEADLESS_JAVA_OPTIONS",
                         runner.tool_env("objdump", self.work))
        with self.assertRaises(ValueError):
            runner.tool_env("ghidra",
                            os.path.join(self.tmp, "with space"))

    def test_jadx_ilspy_extract_argv(self):
        jadx = self.stub("jadx", "exit 0\n")
        ilspy = self.stub("ilspy", "exit 0\n")
        sevenzz = self.stub("sevenzz", "exit 0\n")
        unsquashfs = self.stub("unsquashfs", "exit 0\n")
        ubireader = self.stub("ubireader", "exit 0\n")
        os.environ["JADX_BIN"] = jadx
        os.environ["ILSPY_BIN"] = ilspy
        os.environ["SEVENZZ_BIN"] = sevenzz
        os.environ["UNSQUASHFS_BIN"] = unsquashfs
        os.environ["UBIREADER_BIN"] = ubireader
        self.assertEqual(
            self.dry("jadx", "apk", {"threads": 2}),
            [jadx, "-j", "2", "-d", "/w/jadx-out", "/w/input.bin"])
        with self.assertRaises(ValueError):
            self.dry("jadx", "apk", {"threads": 99})
        self.assertEqual(
            self.dry("ilspy", "assembly", {}),
            [ilspy, "-p", "-o", "/w/ilspy-out", "/w/input.bin"])
        self.assertEqual(
            self.dry("extract", "7zz-extract", {}),
            [sevenzz, "x", "-y", "-o/w/extract-out", "/w/input.bin"])
        self.assertEqual(
            self.dry("extract", "unsquashfs-extract", {}),
            [unsquashfs, "-d", "/w/extract-out", "-f", "/w/input.bin"])
        self.assertEqual(
            self.dry("extract", "ubireader-extract", {}),
            [ubireader, "-o", "/w/extract-out", "/w/input.bin"])
        with self.assertRaises(ValueError):
            self.dry("extract", "tar-extract", {})

    def test_missing_tool_binary_rejected(self):
        os.environ["OBJDUMP_BIN"] = os.path.join(self.tmp, "no-such-bin")
        with self.assertRaises(ValueError):
            self.dry("objdump", "auto", {})

    def test_jadx_argv_has_no_removed_flags(self):
        # Regression net for the ARM64 build failure: jadx 1.5.6 has
        # no --no-src-save-cache flag (verified via --help) and
        # aborts with "Unknown option" when it is passed. The runner
        # argv must stay exactly [bin -j N -d OUT IN].
        jadx = self.stub("jadx", "exit 0\n")
        os.environ["JADX_BIN"] = jadx
        argv = self.dry("jadx", "apk", {"threads": 2})
        self.assertEqual(argv, [jadx, "-j", "2",
                                "-d", "/w/jadx-out", "/w/input.bin"])
        self.assertNotIn("--no-src-save-cache", argv)

    def test_jadx_tool_env_scopes_jadx_dirs_per_job(self):
        # jadx 1.5.6 honors JADX_CACHE_DIR / JADX_TMP_DIR /
        # JADX_CONFIG_DIR (verified via --print-files); without them
        # it resolves the passwd-derived user home and fails to
        # start on the read-only production root. All three live
        # under the job workdir so cleanup removes them.
        env = runner.tool_env("jadx", self.work)
        self.assertEqual(env["JADX_CACHE_DIR"],
                         os.path.join(self.work, "jadx-cache"))
        self.assertEqual(env["JADX_TMP_DIR"],
                         os.path.join(self.work, "jadx-tmp"))
        self.assertEqual(env["JADX_CONFIG_DIR"],
                         os.path.join(self.work, "jadx-config"))
        for name in ("jadx-cache", "jadx-tmp", "jadx-config"):
            self.assertTrue(os.path.isdir(os.path.join(self.work, name)))
        # Other tools keep the minimal env without JADX scoping.
        for tool in ("objdump", "ghidra", "ilspy", "extract"):
            other = runner.tool_env(tool, self.work)
            self.assertNotIn("JADX_CACHE_DIR", other)
            self.assertNotIn("JADX_TMP_DIR", other)
            self.assertNotIn("JADX_CONFIG_DIR", other)

    # -- probe ---------------------------------------------------------

    def test_probe_answer_shape_and_architectures(self):
        objdump = self.stub("objdump", 'echo "GNU objdump 2.40"\n')
        os.environ["OBJDUMP_BIN"] = objdump
        self.submit("objdump", kind="probe")
        self.run_once("objdump")
        answer = self.claim("job1")
        self.assertEqual(answer["tool"], "objdump")
        self.assertIn("2.40", answer["tool_version"])
        self.assertEqual(answer["image_digest"], "test-digest")
        self.assertTrue(answer["runner_arch"])

    def test_objdump_architectures_parsed_like_php(self):
        out = os.path.join(self.tmp, "i.txt")
        with open(out, "w") as fh:
            fh.write(OBJDUMP_I)
        os.environ["OBJDUMP_BIN"] = self.stub(
            "objdump", "cat '%s'\n" % out)
        r = runner.Runner("objdump", self.spool, self.results)
        self.assertEqual(r.objdump_architectures(),
                         ["i386", "iamcu", "arm", "aarch64", "mips"])

    # -- jadx probe fixture qualification -------------------------------

    def jadx_probe(self, stub_body, with_fixture=True):
        fixdir = os.path.join(self.tmp, "fixtures")
        os.makedirs(fixdir, exist_ok=True)
        if with_fixture:
            with open(os.path.join(fixdir, "dex-smoke.dex"), "wb") as fh:
                fh.write(b"dex-fixture-bytes")
        os.environ["RUNNER_FIXTURES"] = fixdir
        os.environ["JADX_BIN"] = self.stub("jadx", stub_body)
        self.submit("jadx", kind="probe")
        self.run_once("jadx")
        return self.claim("job1")

    def test_jadx_probe_reports_fixture_ok_on_marker(self):
        answer = self.jadx_probe(
            'if [ "$1" = "--version" ]; then echo "jadx 1.5.1"; exit 0; fi\n'
            'out=""; prev=""; for a in "$@"; do'
            ' if [ "$prev" = "-d" ]; then out="$a"; fi; prev="$a"; done\n'
            'mkdir -p "$out/com/rev2square"\n'
            'echo "public class FixtureSmoketest {}"'
            ' > "$out/com/rev2square/FixtureSmoketest.java"\n')
        self.assertIn("1.5.1", answer["tool_version"])
        self.assertEqual(answer["fixture"],
                         {"name": "dex-smoke.dex", "ok": True})

    def test_jadx_probe_fixture_fails_closed_on_tool_error(self):
        answer = self.jadx_probe(
            'if [ "$1" = "--version" ]; then echo "jadx 1.5.1"; exit 0; fi\n'
            "exit 1\n")
        self.assertIn("1.5.1", answer["tool_version"])
        self.assertEqual(answer["fixture"],
                         {"name": "dex-smoke.dex", "ok": False})

    def test_jadx_probe_fixture_missing_baked_file(self):
        answer = self.jadx_probe('echo "jadx 1.5.1"\n', with_fixture=False)
        self.assertEqual(answer["fixture"],
                         {"name": "dex-smoke.dex", "ok": False})

    def test_other_tools_probe_without_fixture(self):
        os.environ["OBJDUMP_BIN"] = self.stub(
            "objdump", 'echo "GNU objdump 2.40"\n')
        self.submit("objdump", kind="probe")
        self.run_once("objdump")
        self.assertNotIn("fixture", self.claim("job1"))

    def test_jadx_probe_caches_fixture_per_boot(self):
        fixdir = os.path.join(self.tmp, "fixtures")
        os.makedirs(fixdir)
        with open(os.path.join(fixdir, "dex-smoke.dex"), "wb") as fh:
            fh.write(b"dex-fixture-bytes")
        os.environ["RUNNER_FIXTURES"] = fixdir
        os.environ["JADX_BIN"] = self.stub(
            "jadx",
            'if [ "$1" = "--version" ]; then echo "jadx 1.5.1"; exit 0; fi\n'
            'out=""; prev=""; for a in "$@"; do'
            ' if [ "$prev" = "-d" ]; then out="$a"; fi; prev="$a"; done\n'
            'mkdir -p "$out/com/rev2square"\n'
            'echo "public class FixtureSmoketest {}"'
            ' > "$out/com/rev2square/FixtureSmoketest.java"\n')
        decompiles = []
        orig_check = runner.Runner._check_fixture

        def counting_check(self, claimdir):
            decompiles.append(claimdir)
            return orig_check(self, claimdir)

        runner.Runner._check_fixture = counting_check
        try:
            r = runner.Runner("jadx", self.spool, self.results)
            self.submit("jadx", kind="probe", name="job1")
            r.run_once()
            self.submit("jadx", kind="probe", name="job2")
            r.run_once()
        finally:
            runner.Runner._check_fixture = orig_check
        # One decompile served both probes: qualification is real on
        # the first probe, instant (cached) afterwards.
        self.assertEqual(len(decompiles), 1)
        self.assertEqual(self.claim("job1")["fixture"],
                         {"name": "dex-smoke.dex", "ok": True})
        self.assertEqual(self.claim("job2")["fixture"],
                         {"name": "dex-smoke.dex", "ok": True})

    def counting_jadx_stub(self, fail_until):
        """Jadx stub that counts decompile invocations in a counter file
        beside the claims (derived from the -d outdir: results live
        three levels up) and fails until the nth invocation."""
        return self.stub(
            "jadx",
            'if [ "$1" = "--version" ]; then echo "jadx 1.5.1"; exit 0; fi\n'
            'out=""; prev=""; for a in "$@"; do'
            ' if [ "$prev" = "-d" ]; then out="$a"; fi; prev="$a"; done\n'
            'resdir="$(dirname "$(dirname "$(dirname "$out")")")"\n'
            'n=$(cat "$resdir/attempt-count" 2>/dev/null || echo 0)\n'
            'n=$((n + 1)); echo "$n" > "$resdir/attempt-count"\n'
            'if [ "$n" -lt %d ]; then exit 1; fi\n'
            'mkdir -p "$out/com/rev2square"\n'
            'echo "public class FixtureSmoketest {}"'
            ' > "$out/com/rev2square/FixtureSmoketest.java"\n' % fail_until)

    def attempt_count(self):
        with open(os.path.join(self.results, "attempt-count")) as fh:
            return fh.read().strip()

    def test_jadx_probe_retries_then_qualifies(self):
        fixdir = os.path.join(self.tmp, "fixtures")
        os.makedirs(fixdir)
        with open(os.path.join(fixdir, "dex-smoke.dex"), "wb") as fh:
            fh.write(b"dex-fixture-bytes")
        os.environ["RUNNER_FIXTURES"] = fixdir
        os.environ["JADX_BIN"] = self.counting_jadx_stub(3)
        self.submit("jadx", kind="probe")
        self.run_once("jadx")
        # Two transient failures, then success: bounded retries.
        self.assertEqual(self.claim("job1")["fixture"],
                         {"name": "dex-smoke.dex", "ok": True})
        self.assertEqual(self.attempt_count(), "3")

    def test_jadx_probe_permanent_failure_is_bounded(self):
        fixdir = os.path.join(self.tmp, "fixtures")
        os.makedirs(fixdir)
        with open(os.path.join(fixdir, "dex-smoke.dex"), "wb") as fh:
            fh.write(b"dex-fixture-bytes")
        os.environ["RUNNER_FIXTURES"] = fixdir
        os.environ["JADX_BIN"] = self.counting_jadx_stub(99)
        self.submit("jadx", kind="probe")
        self.run_once("jadx")
        # A broken install fails closed after exactly FIXTURE_ATTEMPTS
        # (no infinite retry), while the probe still answers version.
        answer = self.claim("job1")
        self.assertIn("1.5.1", answer["tool_version"])
        self.assertEqual(answer["fixture"],
                         {"name": "dex-smoke.dex", "ok": False})
        self.assertEqual(self.attempt_count(), "3")

    def test_probe_bounds_pinned(self):
        # Coordinator budgets derive from these (protocol doc section
        # 1; the PHP suite pins the arithmetic): changing any bound
        # requires deliberately re-deriving the coordinator deadline.
        self.assertEqual(runner.VERSION_TIMEOUT_SECONDS, 30)
        self.assertEqual(runner.FIXTURE_WALL_SECONDS, 30)
        self.assertEqual(runner.FIXTURE_ATTEMPTS, 3)

    def test_write_answer_never_raises(self):
        # A coordinator that timed out may clean its submission side
        # while the runner still works; losing that race must not
        # kill the runner loop.
        r = runner.Runner("jadx", self.spool, self.results)
        self.assertFalse(r._write_answer(
            os.path.join(self.tmp, "no-such-claimdir"), {"schema": 1}))

    def test_committed_dex_fixture_parses(self):
        path = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                            "..", "fixtures", "dex-smoke.dex")
        with open(path, "rb") as fh:
            blob = fh.read()
        self.assertEqual(blob[:8], b"dex\n035\x00")
        (adler,) = struct.unpack_from("<I", blob, 8)
        self.assertEqual(adler, zlib.adler32(blob[12:]) & 0xFFFFFFFF)
        self.assertEqual(blob[12:32], hashlib.sha1(blob[32:]).digest())
        (file_size,) = struct.unpack_from("<I", blob, 0x20)
        self.assertEqual(file_size, len(blob))

    # -- analyze happy path --------------------------------------------

    def test_objdump_happy_path(self):
        os.environ["OBJDUMP_BIN"] = self.stub(
            "objdump", 'printf "    8000:\\t55   \\tpush ebp\\n"\n')
        self.submit("objdump")
        self.run_once("objdump")
        status = self.claim("job1")
        self.assertEqual(status["state"], "done")
        self.assertEqual(status["exit_code"], 0)
        with open(os.path.join(self.results, "job1", "tool.out")) as fh:
            self.assertIn("push ebp", fh.read())
        # The tool never saw spool paths: staged copy only.
        self.assertFalse(os.path.exists(os.path.join(self.work, "job1")))

    def test_input_sha_mismatch_fails_closed(self):
        os.environ["OBJDUMP_BIN"] = self.stub("objdump", "echo hi\n")
        name = self.submit("objdump")
        with open(os.path.join(self.spool, name, "input.bin"), "wb") as fh:
            fh.write(b"tampered!!")
        self.run_once("objdump")
        status = self.claim("job1")
        self.assertEqual(status["state"], "failed")
        self.assertIn("sha256", status["error"])

    def test_traversal_input_name_rejected(self):
        os.environ["OBJDUMP_BIN"] = self.stub("objdump", "echo hi\n")
        jobdir = os.path.join(self.spool, "job1")
        os.makedirs(jobdir)
        with open(os.path.join(jobdir, "job.json"), "w") as fh:
            json.dump({"schema": 1, "tool": "objdump", "kind": "analyze",
                       "profile": "auto", "params": {}, "input": "../evil",
                       "input_size": 1, "input_sha256": "x" * 64,
                       "limits": {"wall_seconds": 5}}, fh)
        self.run_once("objdump")
        self.assertEqual(self.claim("job1")["state"], "failed")

    def test_second_runner_does_not_double_claim(self):
        os.environ["OBJDUMP_BIN"] = self.stub("objdump", "echo hi\n")
        self.submit("objdump")
        self.run_once("objdump")
        first = self.claim("job1")
        self.run_once("objdump")  # nothing pending anymore
        self.assertEqual(self.claim("job1"), first)

    def test_wrong_tool_ignored(self):
        os.environ["OBJDUMP_BIN"] = self.stub("objdump", "echo hi\n")
        self.submit("ghidra", profile="headless",
                    params={"language": "ARM:LE:32:v8", "base": 0})
        self.run_once("objdump")
        self.assertEqual(os.listdir(self.results), [])

    # -- timeout / cancel / tree kill -----------------------------------

    def test_timeout_kills_whole_tree(self):
        grandchild = os.path.join(self.tmp, "grandchild.pid")
        os.environ["OBJDUMP_BIN"] = self.stub(
            "objdump",
            "sleep 30 & echo $! > '%s'\nwait\n" % grandchild)
        self.submit("objdump", wall=2)
        self.run_once("objdump")
        self.assertEqual(self.claim("job1")["state"], "timeout")
        with open(grandchild) as fh:
            pid = int(fh.read().strip())
        time.sleep(0.5)
        with self.assertRaises(ProcessLookupError):
            os.kill(pid, 0)

    def test_cancel_kills_whole_tree(self):
        grandchild = os.path.join(self.tmp, "gc2.pid")
        os.environ["OBJDUMP_BIN"] = self.stub(
            "objdump",
            "sleep 30 & echo $! > '%s'\nwait\n" % grandchild)
        name = self.submit("objdump", wall=60)
        import threading
        t = threading.Thread(target=self.run_once, args=("objdump",))
        t.start()
        # Wait for the claim, then cancel like the coordinator would.
        for _ in range(100):
            if os.path.isdir(os.path.join(self.results, name)):
                break
            time.sleep(0.1)
        with open(os.path.join(self.results, name, "cancel"), "w") as fh:
            fh.write("cancelled\n")
        t.join(timeout=30)
        self.assertFalse(t.is_alive())
        self.assertEqual(self.claim(name)["state"], "cancelled")
        with open(grandchild) as fh:
            pid = int(fh.read().strip())
        time.sleep(0.5)
        with self.assertRaises(ProcessLookupError):
            os.kill(pid, 0)

    # -- tree collection -------------------------------------------------

    def test_jadx_tree_collection_skips_symlinks(self):
        script = (
            "mkdir -p \"$4\"/sources/com\n"
            "echo 'class Foo{}' > \"$4\"/sources/com/Foo.java\n"
            "ln -s /etc/passwd \"$4\"/sources/escape\n"
            "mkdir -p \"$4\"/deep/a/b/c/d/e/f/g/h/i/j/k/l\n"
            "echo deep > \"$4\"/deep/a/b/c/d/e/f/g/h/i/j/k/l/x.txt\n")
        # jadx argv: [bin -j N -d OUT IN] → OUT is $4
        os.environ["JADX_BIN"] = self.stub("jadx", script)
        self.submit("jadx", profile="apk", params={"threads": 2})
        self.run_once("jadx")
        self.assertEqual(self.claim("job1")["state"], "done")
        with open(os.path.join(self.results, "job1", "evidence.json")) as fh:
            evidence = json.load(fh)
        paths = [f["path"] for f in evidence["files"]]
        self.assertIn("sources/com/Foo.java", paths)
        self.assertNotIn("sources/escape", paths)
        self.assertTrue(evidence["truncated"])  # depth cap hit
        self.assertEqual(evidence["inline"]["sources/com/Foo.java"].strip(),
                         "class Foo{}")

    def test_ghidra_oversized_evidence_rejected(self):
        script = ("python3 -c 'import json;json.dump("
                  '{"schema":1,"pad":"x"*600000},'
                  "open(\"evidence.json\",\"w\"))'\n")
        os.environ["GHIDRA_HEADLESS"] = self.stub("ghidra", script)
        self.submit("ghidra", profile="headless",
                    params={"language": "ARM:LE:32:v8", "base": 0})
        self.run_once("ghidra")
        status = self.claim("job1")
        self.assertEqual(status["state"], "failed")
        self.assertIn("byte cap", status["error"])

    def test_ghidra_missing_evidence_rejected(self):
        # Headless exits 0 even when the post-script fails: a missing
        # evidence file must fail the job with an actionable error.
        os.environ["GHIDRA_HEADLESS"] = self.stub("ghidra", "exit 0\n")
        self.submit("ghidra", profile="headless",
                    params={"language": "ARM:LE:32:v8", "base": 0})
        self.run_once("ghidra")
        status = self.claim("job1")
        self.assertEqual(status["state"], "failed")
        self.assertIn("no evidence.json", status["error"])

    def test_ghidra_version_from_usage_proof_and_properties(self):
        # analyzeHeadless has NO --version flag (verified against
        # 12.2): the runner proves execution via the usage run and
        # reads the version from application.properties. Exit code
        # is ignored (usage exits 1); the marker is required.
        home = os.path.join(self.tmp, "fakehome")
        os.makedirs(os.path.join(home, "support"))
        os.makedirs(os.path.join(home, "Ghidra"))
        launcher = os.path.join(home, "support", "analyzeHeadless")
        with open(launcher, "w") as fh:
            fh.write('#!/bin/sh\necho "Headless Analyzer Usage: analyzeHeadless"\nexit 1\n')
        os.chmod(launcher, 0o755)
        with open(os.path.join(home, "Ghidra",
                               "application.properties"), "w") as fh:
            fh.write("# comment\napplication.layout.version=3\n"
                     "application.version=12.2-test\n"
                     "application.version.extra=nope\n")
        os.environ["GHIDRA_HEADLESS"] = launcher
        r = runner.Runner("ghidra", self.spool, self.results)
        self.assertEqual(r.tool_version(), "12.2-test")

        # Usage marker absent (wrong tool output): fail closed.
        with open(launcher, "w") as fh:
            fh.write('#!/bin/sh\necho "hi"\nexit 0\n')
        self.assertIsNone(r.tool_version())

        # Properties missing: fail closed even with the marker.
        with open(launcher, "w") as fh:
            fh.write('#!/bin/sh\necho "Headless Analyzer Usage: x"\nexit 1\n')
        os.unlink(os.path.join(home, "Ghidra", "application.properties"))
        self.assertIsNone(r.tool_version())

        # Launcher unrunnable: fail closed.
        os.environ["GHIDRA_HEADLESS"] = os.path.join(self.tmp, "nope")
        self.assertIsNone(r.tool_version())

    def test_ghidra_properties_value_bounded(self):
        home = os.path.join(self.tmp, "fakehome2")
        os.makedirs(os.path.join(home, "Ghidra"))
        with open(os.path.join(home, "Ghidra",
                               "application.properties"), "w") as fh:
            fh.write("application.version=" + "v" * 200 + "\n")
        got = runner._ghidra_properties_version(
            os.path.join(home, "support", "analyzeHeadless"))
        self.assertEqual(len(got), 64)
        # Symlinked properties never read.
        os.unlink(os.path.join(home, "Ghidra", "application.properties"))
        os.symlink("/etc/hostname",
                   os.path.join(home, "Ghidra", "application.properties"))
        self.assertIsNone(runner._ghidra_properties_version(
            os.path.join(home, "support", "analyzeHeadless")))

    def test_log_helpers_sanitize(self):
        self.assertEqual(runner._safe_id("job1"), "job1")
        self.assertEqual(runner._safe_id("a/b\\c d"), "a?b?c?d")
        self.assertEqual(len(runner._safe_id("x" * 200)), 64)
        self.assertEqual(runner._one_line("a\nb\t c"), "a b c")
        self.assertEqual(len(runner._one_line("y" * 500)), 120)

    def _adversarial_tree(self, root, nfiles=3000):
        """Many-class-shaped tree: varied sizes, unicode names and
        content (JSON escaping growth), one huge file, one binary,
        one symlink, one deep path."""
        os.makedirs(root, exist_ok=True)
        for i in range(nfiles):
            sub = os.path.join(root, "sources", "com", "twin%02d" % (i % 64))
            os.makedirs(sub, exist_ok=True)
            name = "Widget%d.java" % i
            if i % 7 == 3:
                name = "Ünïcöde%d.java" % i
            body = ("package com.twin%02d;\npublic class W%d {\n"
                    "    // \u6d4b\u8bd5 \U0001f600 commentary\n"
                    "    public static void ping() {}\n}\n"
                    % (i % 64, i))
            # Every 50th file exceeds the full-inline bound (excerpt).
            if i % 50 == 0:
                body += "// padding\n" * 4000
            with open(os.path.join(sub, name), "w",
                      encoding="utf-8") as fh:
                fh.write(body)
        with open(os.path.join(root, "huge.bin"), "wb") as fh:
            fh.write(b"\x00\xff" * 600000)
        with open(os.path.join(root, "binary.dat"), "wb") as fh:
            # Deterministic invalid UTF-8 (urandom would break the
            # determinism assertion via the manifest sha).
            fh.write(b"\xff" + bytes((i * 37 + 11) % 256 for i in range(999)))
        os.symlink("sources",
                   os.path.join(root, "escape"))
        deep = os.path.join(root, *["d%d" % i for i in range(15)])
        os.makedirs(deep, exist_ok=True)
        with open(os.path.join(deep, "x.txt"), "w") as fh:
            fh.write("deep\n")
        return root

    def _reconciles(self, budget, manifest, inline):
        self.assertEqual(
            budget["files_seen"],
            budget["manifest_retained"] + budget["manifest_omitted"])
        self.assertEqual(
            budget["manifest_retained"],
            budget["inline_full"] + budget["excerpt_count"]
            + budget["content_omitted"] + budget["binary_skipped"])
        self.assertEqual(budget["manifest_retained"], len(manifest))
        self.assertEqual(
            budget["inline_full"] + budget["excerpt_count"], len(inline))
        self.assertEqual(budget["excerpt_count"],
                         len(budget["excerpt_paths"]))
        for path in budget["excerpt_paths"]:
            self.assertIn(path, inline)
            self.assertIn("[excerpt: first ", inline[path])

    def test_tree_evidence_bounded_and_reconciled(self):
        outdir = self._adversarial_tree(os.path.join(self.tmp, "out"))
        r = runner.Runner("jadx", self.spool, self.results)
        manifest, inline, truncated, budget = r._collect_tree(outdir)
        evidence = {"schema": runner.SCHEMA, "tool": "jadx",
                    "exit_code": 0, "profile": "apk",
                    "files": manifest, "inline": inline,
                    "truncated": truncated, "budget": budget}
        blob = runner._dumps(evidence)
        self.assertLessEqual(len(blob), runner.MAX_EVIDENCE_BYTES)
        self.assertTrue(truncated)
        self.assertTrue(budget["manifest_omitted"] > 0)
        self.assertTrue(budget["excerpt_count"] > 0)
        # Sources-first: with 3000 sources for 2000 manifest slots,
        # every retained entry is a source — the binaries lose their
        # manifest slots (omitted, never silently dropped) instead
        # of crowding sources out.
        self.assertTrue(
            all(m["path"].endswith(".java") for m in manifest))
        self.assertTrue(budget["depth_pruned"])
        self.assertEqual(budget["symlink_skipped"], 1)
        self._reconciles(budget, manifest, inline)
        # Every retained string is valid UTF-8 (char-boundary cuts).
        for text in inline.values():
            text.encode("utf-8")
        # Deterministic: identical bytes across collections.
        again = r._collect_tree(outdir)
        self.assertEqual(
            blob,
            runner._dumps({"schema": runner.SCHEMA, "tool": "jadx",
                            "exit_code": 0, "profile": "apk",
                            "files": again[0], "inline": again[1],
                            "truncated": again[2], "budget": again[3]}))

    def test_tree_evidence_fits_any_pool(self):
        # Pool accounting degrades gracefully at any pool size
        # (monkeypatched tiny here): valid partial evidence, counts
        # reconcile, truncation explicit. The fit-loop backstop is
        # covered separately below.
        outdir = self._adversarial_tree(os.path.join(self.tmp, "out2"),
                                        nfiles=300)
        r = runner.Runner("jadx", self.spool, self.results)
        old_pool = runner.EVIDENCE_POOL_BYTES
        runner.EVIDENCE_POOL_BYTES = 4000
        try:
            manifest, inline, truncated, budget = r._collect_tree(outdir)
        finally:
            runner.EVIDENCE_POOL_BYTES = old_pool
        evidence = {"schema": runner.SCHEMA, "tool": "jadx",
                    "exit_code": 0, "profile": "apk",
                    "files": manifest, "inline": inline,
                    "truncated": truncated, "budget": budget}
        claimdir = os.path.join(self.results, "tiny")
        os.makedirs(claimdir)
        r._write_evidence(claimdir, evidence)
        with open(os.path.join(claimdir, "evidence.json")) as fh:
            stored = json.load(fh)
        self.assertLessEqual(len(runner._dumps(stored)),
                             runner.MAX_EVIDENCE_BYTES)
        self.assertTrue(stored["truncated"])
        self._reconciles(stored["budget"], stored["files"],
                         stored["inline"])

    def test_write_evidence_fit_loop_drops_deterministically(self):
        # Hand-built oversized evidence: the loop drops trailing
        # excerpts, then trailing inline files, then trailing
        # manifest entries, and recomputes every count.
        manifest = [{"path": "f%04d.java" % i, "size": 10,
                     "sha256": "0" * 64} for i in range(2000)]
        inline = {"f%04d.java" % i: "x" * 1000 for i in range(600)}
        budget = {"files_seen": 2000, "manifest_retained": 2000,
                  "manifest_omitted": 0, "inline_full": 600,
                  "excerpt_count": 0, "content_omitted": 1400,
                  "binary_skipped": 0, "symlink_skipped": 0,
                  "error_skipped": 0, "excerpt_paths": [],
                  "depth_pruned": False, "walk_capped": False,
                  "byte_cap": runner.MAX_EVIDENCE_BYTES}
        evidence = {"schema": runner.SCHEMA, "tool": "jadx",
                    "exit_code": 0, "profile": "apk",
                    "files": manifest, "inline": inline,
                    "truncated": False, "budget": budget}
        self.assertGreater(len(runner._dumps(evidence)),
                           runner.MAX_EVIDENCE_BYTES)
        r = runner.Runner("jadx", self.spool, self.results)
        claimdir = os.path.join(self.results, "fit")
        os.makedirs(claimdir)
        r._write_evidence(claimdir, evidence)
        with open(os.path.join(claimdir, "evidence.json")) as fh:
            stored = json.load(fh)
        self.assertLessEqual(len(runner._dumps(stored)),
                             runner.MAX_EVIDENCE_BYTES)
        self.assertTrue(stored["truncated"])
        # Deterministic: earliest files survive.
        self.assertEqual(stored["files"][0]["path"], "f0000.java")
        self._reconciles(stored["budget"], stored["files"],
                         stored["inline"])

    def test_tree_evidence_excerpt_paths_byte_bounded(self):
        # Long paths cannot blow the metadata: the excerpt path list
        # is byte-bounded (deterministic earliest-wins) on top of the
        # count bound.
        outdir = os.path.join(self.tmp, "longpaths")
        os.makedirs(outdir)
        for i in range(60):
            name = "d%02d-" % i + "p" * 190 + ".java"
            with open(os.path.join(outdir, name), "w") as fh:
                fh.write("// pad\n" * 6000)
        r = runner.Runner("jadx", self.spool, self.results)
        manifest, inline, truncated, budget = r._collect_tree(outdir)
        paths_blob = runner._dumps(budget["excerpt_paths"])
        self.assertLessEqual(len(paths_blob),
                             runner.EXCERPT_PATHS_BUDGET + 2)
        self.assertLess(len(budget["excerpt_paths"]), 60)
        self._reconciles(budget, manifest, inline)

    def test_tree_evidence_preserves_markup_in_content(self):
        # The runner never strips or mangles content: HTML-like
        # strings in Java sources and HTML assets pass through
        # byte-exact (content rules live in the coordinator, which
        # exempts bulk source text while escaping every render).
        outdir = os.path.join(self.tmp, "markup")
        os.makedirs(os.path.join(outdir, "sources", "com"))
        os.makedirs(os.path.join(outdir, "resources", "assets"))
        java = ('class B { String a = "<svg onload=alert(1)>";\n'
                ' String b = "javascript:x"; }\n')
        html = ("<html><head><script></script></head>"
                "<body><iframe></iframe></body></html>")
        with open(os.path.join(outdir, "sources", "com", "B.java"),
                  "w") as fh:
            fh.write(java)
        with open(os.path.join(outdir, "resources", "assets",
                               "page.html"), "w") as fh:
            fh.write(html)
        r = runner.Runner("jadx", self.spool, self.results)
        manifest, inline, truncated, budget = r._collect_tree(outdir)
        self.assertFalse(truncated)
        self.assertEqual(inline["sources/com/B.java"], java)
        self.assertEqual(inline["resources/assets/page.html"], html)
        self._reconciles(budget, manifest, inline)

    def test_tree_evidence_prefers_sources_over_resources(self):
        # Production run #5 showed the failure mode: a pure
        # lexicographic walk lets resources/ starve sources/ out of
        # the manifest budget (2000 entries = 1200 resources + 800
        # sources retained, 400 sources omitted). Sources must win
        # every budget first: decompiled code is the evidence, the
        # resource listing is the index.
        outdir = os.path.join(self.tmp, "mixed")
        srcdir = os.path.join(outdir, "sources", "com", "app")
        resdir = os.path.join(outdir, "resources", "res")
        os.makedirs(srcdir)
        os.makedirs(resdir)
        java_paths = []
        for i in range(100):
            rel = "sources/com/app/C%03d.java" % i
            java_paths.append(rel)
            with open(os.path.join(outdir, rel), "w") as fh:
                fh.write("package com.app;\npublic class C%03d {}\n" % i)
        for i in range(300):
            with open(os.path.join(resdir, "v%03d.xml" % i),
                      "w") as fh:
                fh.write("<v>%d</v>\n" % i)
        old_max = runner.MAX_MANIFEST_FILES
        runner.MAX_MANIFEST_FILES = 150
        try:
            r = runner.Runner("jadx", self.spool, self.results)
            manifest, inline, truncated, budget = r._collect_tree(outdir)
        finally:
            runner.MAX_MANIFEST_FILES = old_max
        self.assertTrue(truncated)
        kept = {m["path"] for m in manifest}
        # Every source survives; resources fill what is left.
        for rel in java_paths:
            self.assertIn(rel, kept)
        self.assertEqual(len(manifest), 150)
        for rel in java_paths:
            self.assertIn(rel, inline)
        self._reconciles(budget, manifest, inline)

    def test_archive_tree_retains_complete_output(self):
        # The retained archive carries the COMPLETE tree the bounded
        # evidence only samples: sources, resources, and binaries,
        # plus a provenance file with honest coverage. Symlinks are
        # never archived (counted, like everywhere else).
        outdir = os.path.join(self.tmp, "tree")
        os.makedirs(os.path.join(outdir, "sources", "com"))
        os.makedirs(os.path.join(outdir, "resources"))
        with open(os.path.join(outdir, "sources", "com", "A.java"),
                  "w") as fh:
            fh.write("class A{}\n")
        with open(os.path.join(outdir, "resources",
                               "AndroidManifest.xml"), "w") as fh:
            fh.write("<manifest/>\n")
        with open(os.path.join(outdir, "resources", "icon.png"),
                  "wb") as fh:
            fh.write(b"\x89PNG\r\n\x1a\n" + b"\x00" * 100)
        os.symlink("sources", os.path.join(outdir, "escape"))
        r = runner.Runner("jadx", self.spool, self.results)
        claimdir = os.path.join(self.results, "zip")
        os.makedirs(claimdir)
        meta = r._archive_tree(outdir, claimdir, "apk", 0)
        blob = os.path.join(claimdir, "output.zip")
        self.assertTrue(os.path.isfile(blob))
        with open(blob, "rb") as fh:
            digest = hashlib.sha256(fh.read()).hexdigest()
        self.assertEqual(digest, meta["sha256"])
        self.assertEqual(meta["file"], "output.zip")
        self.assertEqual(meta["files"], 3)
        self.assertEqual(meta["files_seen"], 3)
        self.assertFalse(meta["truncated"])
        self.assertIsNone(meta["reason"])
        import zipfile
        with zipfile.ZipFile(blob) as zf:
            names = zf.namelist()
            self.assertEqual(
                sorted(names),
                ["resources/AndroidManifest.xml",
                 "resources/icon.png",
                 "rev2square-output.json",
                 "sources/com/A.java"])
            self.assertEqual(zf.read("sources/com/A.java"),
                             b"class A{}\n")
            for info in zf.infolist():
                self.assertEqual(info.external_attr >> 16, 0o644)
                self.assertEqual(info.date_time,
                                 runner.ARCHIVE_ENTRY_DATE)
            provenance = json.loads(
                zf.read("rev2square-output.json").decode("utf-8"))
        self.assertEqual(provenance["tool"], "jadx")
        self.assertEqual(provenance["profile"], "apk")
        self.assertIn("not the original source",
                      provenance["warning"])
        coverage = provenance["coverage"]
        self.assertEqual(coverage["files_seen"], 3)
        self.assertEqual(coverage["files_archived"], 3)
        self.assertEqual(coverage["symlink_skipped"], 1)
        self.assertFalse(coverage["truncated"])
        # Deterministic content: a second archive carries identical
        # entry bytes, names, modes, and dates (only the archived_at
        # provenance timestamp differs, as data, not bytes).
        before = int(time.time())
        claimdir2 = os.path.join(self.results, "zip2")
        os.makedirs(claimdir2)
        r._archive_tree(outdir, claimdir2, "apk", 0)
        with zipfile.ZipFile(blob) as first, zipfile.ZipFile(
                os.path.join(claimdir2, "output.zip")) as second:
            snap = lambda zf: sorted(
                (i.filename, i.date_time, i.external_attr,
                 zf.read(i.filename))
                for i in zf.infolist()
                if i.filename != "rev2square-output.json")
            self.assertEqual(snap(first), snap(second))
            stamped = json.loads(second.read(
                "rev2square-output.json").decode("utf-8"))
            self.assertGreaterEqual(stamped["archived_at"], before)
            self.assertLessEqual(stamped["archived_at"],
                                 int(time.time()))

    def test_archive_tree_caps_shed_binaries_first(self):
        # Archive caps are explicit and sources-first: when the file
        # cap bites, binaries are omitted before code, and the reason
        # names the cap so the UI never presents it as complete.
        outdir = os.path.join(self.tmp, "cap")
        os.makedirs(os.path.join(outdir, "sources"))
        os.makedirs(os.path.join(outdir, "resources"))
        for i in range(5):
            with open(os.path.join(outdir, "sources", "C%d.java" % i),
                      "w") as fh:
                fh.write("class C%d{}\n" % i)
        for i in range(5):
            with open(os.path.join(outdir, "resources",
                                   "i%d.png" % i), "wb") as fh:
                fh.write(b"\x89PNG" + bytes([i]) * 50)
        old_max = runner.MAX_ARCHIVE_FILES
        runner.MAX_ARCHIVE_FILES = 4
        try:
            r = runner.Runner("jadx", self.spool, self.results)
            claimdir = os.path.join(self.results, "zipcap")
            os.makedirs(claimdir)
            meta = r._archive_tree(outdir, claimdir, "apk", 0)
        finally:
            runner.MAX_ARCHIVE_FILES = old_max
        self.assertEqual(meta["files"], 4)
        self.assertEqual(meta["files_seen"], 10)
        self.assertTrue(meta["truncated"])
        self.assertEqual(meta["reason"], "file_cap_reached")
        import zipfile
        with zipfile.ZipFile(
                os.path.join(claimdir, "output.zip")) as zf:
            names = [n for n in zf.namelist()
                     if n != "rev2square-output.json"]
            # All four slots went to sources: nothing shed but pngs.
            self.assertTrue(all(n.endswith(".java") for n in names))
            provenance = json.loads(
                zf.read("rev2square-output.json").decode("utf-8"))
        self.assertTrue(provenance["coverage"]["truncated"])
        self.assertEqual(provenance["coverage"]["reason"],
                         "file_cap_reached")

    def test_archive_tree_byte_cap_is_explicit(self):
        outdir = os.path.join(self.tmp, "bytecap")
        os.makedirs(outdir)
        with open(os.path.join(outdir, "A.java"), "w") as fh:
            fh.write("class A{}\n")
        with open(os.path.join(outdir, "big.bin"), "wb") as fh:
            fh.write(b"\x00" * 4096)
        old_max = runner.MAX_ARCHIVE_UNCOMPRESSED_BYTES
        runner.MAX_ARCHIVE_UNCOMPRESSED_BYTES = 100
        try:
            r = runner.Runner("jadx", self.spool, self.results)
            claimdir = os.path.join(self.results, "zipbyte")
            os.makedirs(claimdir)
            meta = r._archive_tree(outdir, claimdir, "apk", 0)
        finally:
            runner.MAX_ARCHIVE_UNCOMPRESSED_BYTES = old_max
        # Sources-first: the small source fits, the binary trips the
        # byte cap, and the metadata says exactly that.
        self.assertEqual(meta["files"], 1)
        self.assertEqual(meta["files_seen"], 2)
        self.assertTrue(meta["truncated"])
        self.assertEqual(meta["reason"], "byte_cap_reached")
        import zipfile
        with zipfile.ZipFile(
                os.path.join(claimdir, "output.zip")) as zf:
            self.assertIn("A.java", zf.namelist())
            self.assertNotIn("big.bin", zf.namelist())

    def test_collect_writes_output_archive(self):
        # End to end through a stub tool: the claim carries evidence
        # AND the retained archive, with the evidence hash matching
        # the zip bytes.
        os.environ["JADX_BIN"] = self.stub(
            "jadx",
            "mkdir -p \"$4\"/sources\n"
            "printf 'class T{}\\n' > \"$4\"/sources/T.java\n")
        self.submit("jadx", profile="apk", params={"threads": 1})
        self.run_once("jadx")
        self.assertEqual(self.claim("job1")["state"], "done")
        with open(os.path.join(self.results, "job1",
                               "evidence.json")) as fh:
            evidence = json.load(fh)
        archive = evidence["output_archive"]
        blob = os.path.join(self.results, "job1", archive["file"])
        self.assertTrue(os.path.isfile(blob))
        with open(blob, "rb") as fh:
            digest = hashlib.sha256(fh.read()).hexdigest()
        self.assertEqual(digest, archive["sha256"])
        self.assertEqual(os.path.getsize(blob), archive["bytes"])
        import zipfile
        with zipfile.ZipFile(blob) as zf:
            self.assertIn("sources/T.java", zf.namelist())
            self.assertIn("rev2square-output.json", zf.namelist())

    def test_tree_evidence_small_tree_complete(self):
        # Small outputs keep the old complete behavior: everything
        # retained, truncated false, counts exact.
        outdir = os.path.join(self.tmp, "small")
        os.makedirs(os.path.join(outdir, "sources"))
        with open(os.path.join(outdir, "sources", "A.java"), "w") as fh:
            fh.write("class A{}\n")
        r = runner.Runner("jadx", self.spool, self.results)
        manifest, inline, truncated, budget = r._collect_tree(outdir)
        self.assertFalse(truncated)
        self.assertEqual(budget["files_seen"], 1)
        self.assertEqual(budget["manifest_retained"], 1)
        self.assertEqual(budget["inline_full"], 1)
        self.assertEqual(inline["sources/A.java"], "class A{}\n")
        self._reconciles(budget, manifest, inline)

    def test_tree_evidence_binary_retained_as_reference(self):
        # A retained binary counts as binary_skipped (manifest keeps
        # the reference; inline was never promised), never as a
        # content omission — and a binary alone never truncates.
        outdir = os.path.join(self.tmp, "withbin")
        os.makedirs(os.path.join(outdir, "sources"))
        os.makedirs(os.path.join(outdir, "resources"))
        with open(os.path.join(outdir, "sources", "A.java"), "w") as fh:
            fh.write("class A{}\n")
        with open(os.path.join(outdir, "resources", "icon.png"),
                  "wb") as fh:
            fh.write(b"\x89PNG\r\n\x1a\n\xff\xfe\x00binary")
        r = runner.Runner("jadx", self.spool, self.results)
        manifest, inline, truncated, budget = r._collect_tree(outdir)
        self.assertFalse(truncated)
        self.assertEqual(budget["files_seen"], 2)
        self.assertEqual(budget["manifest_retained"], 2)
        self.assertEqual(budget["binary_skipped"], 1)
        self.assertEqual(budget["content_omitted"], 0)
        self.assertEqual(inline, {"sources/A.java": "class A{}\n"})
        self._reconciles(budget, manifest, inline)

    def test_probe_answer_atomic_no_tmp_residue(self):
        os.environ["OBJDUMP_BIN"] = self.stub(
            "objdump", 'echo "GNU objdump 2.40"\n')
        self.submit("objdump", kind="probe")
        self.run_once("objdump")
        claimdir = os.path.join(self.results, "job1")
        self.assertFalse(os.path.exists(
            os.path.join(claimdir, "status.json.tmp")))
        with open(os.path.join(claimdir, "status.json")) as fh:
            self.assertEqual(json.load(fh)["tool"], "objdump")

    def test_ghidra_wrong_envelope_rejected(self):
        script = ("python3 -c 'import json;json.dump("
                  '{"schema":99,"tool":"other"},'
                  "open(\"evidence.json\",\"w\"))'\n")
        os.environ["GHIDRA_HEADLESS"] = self.stub("ghidra", script)
        self.submit("ghidra", profile="headless",
                    params={"language": "ARM:LE:32:v8", "base": 0})
        self.run_once("ghidra")
        status = self.claim("job1")
        self.assertEqual(status["state"], "failed")
        self.assertIn("envelope invalid", status["error"])

    def test_extract_stored_files_rehashed(self):
        # ubireader argv: [bin -o OUT IN] → OUT is $2.
        os.environ["UBIREADER_BIN"] = self.stub(
            "ubi", "mkdir -p \"$2\"\nprintf 'ELFCONTENTS' > \"$2\"/app.bin\n")
        self.submit("extract", profile="ubireader-extract")
        self.run_once("extract")
        self.assertEqual(self.claim("job1")["state"], "done")
        with open(os.path.join(self.results, "job1", "evidence.json")) as fh:
            evidence = json.load(fh)
        self.assertEqual(len(evidence["stored_files"]), 1)
        stored = evidence["stored_files"][0]
        self.assertEqual(stored["path"], "app.bin")
        blob = os.path.join(self.results, "job1", "files",
                            stored["sha256"] + ".bin")
        self.assertTrue(os.path.isfile(blob))
        with open(blob, "rb") as fh:
            self.assertEqual(
                hashlib.sha256(fh.read()).hexdigest(), stored["sha256"])


class RealJadxCase(unittest.TestCase):
    """REAL-EXECUTABLE jadx tests (not stubs).

    Runs the actual pinned jadx 1.5.6 binary against the real
    dex-smoke.dex fixture using the REAL runner argv builder and
    per-job env — the same qualification the Dockerfile performs
    at image build time. Skipped unless REAL_JADX_BIN points at an
    executable jadx with a working JVM (a skip here is an honest
    "not run", never a green).
    """

    @classmethod
    def setUpClass(cls):
        cls.jadx = os.environ.get("REAL_JADX_BIN", "")
        if not (cls.jadx and os.path.isfile(cls.jadx)
                and os.access(cls.jadx, os.X_OK)):
            raise unittest.SkipTest(
                "REAL_JADX_BIN not set to an executable jadx")
        try:
            proc = subprocess.run(
                [cls.jadx, "--version"], stdin=subprocess.DEVNULL,
                stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                timeout=120)
        except (OSError, subprocess.SubprocessError) as exc:
            raise unittest.SkipTest("real jadx not runnable: %s" % exc)
        if proc.returncode != 0:
            raise unittest.SkipTest(
                "real jadx --version failed: %r"
                % proc.stdout[:200].decode("utf-8", "replace"))
        cls.version = proc.stdout.decode("utf-8", "replace").strip()
        cls.here = os.path.dirname(os.path.abspath(__file__))
        cls.fixture = os.path.join(cls.here, "..", "fixtures",
                                   "dex-smoke.dex")

    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="real-jadx-")
        self.old_env = dict(os.environ)
        os.environ["JADX_BIN"] = self.jadx

    def tearDown(self):
        os.environ.clear()
        os.environ.update(self.old_env)
        shutil.rmtree(self.tmp, ignore_errors=True)

    def test_real_help_rejects_removed_option(self):
        # The incident regression: the runner/Dockerfile MUST NOT
        # pass flags the pinned binary does not know. Every flag in
        # the runner argv must appear in the real --help, and the
        # removed flag must be absent from --help entirely.
        proc = subprocess.run(
            [self.jadx, "--help"], stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            timeout=120)
        self.assertEqual(proc.returncode, 0)
        help_text = proc.stdout.decode("utf-8", "replace")
        self.assertNotIn("--no-src-save-cache", help_text)
        workdir = os.path.join(self.tmp, "dry")
        os.makedirs(workdir)
        argv = runner.build_argv("jadx", "apk", {"threads": 2},
                                workdir,
                                os.path.join(workdir, "input.dex"))
        for flag in ("-j", "-d"):
            self.assertIn(flag, argv)
            self.assertIn(flag, help_text)

    def test_real_decompile_fixture_via_runner_argv(self):
        # Same qualification as Dockerfile.jadx: the REAL builder
        # argv + the REAL per-job env must decompile dex-smoke.dex
        # and emit Java containing the FixtureSmoketest class.
        workdir = os.path.join(self.tmp, "work")
        os.makedirs(workdir)
        argv = runner.build_argv("jadx", "apk", {"threads": 1},
                                workdir, self.fixture)
        proc = subprocess.run(
            argv, stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            cwd=workdir, timeout=300,
            env=runner.tool_env("jadx", workdir))
        self.assertEqual(
            proc.returncode, 0,
            "real jadx failed: %r"
            % proc.stdout[:2000].decode("utf-8", "replace"))
        outdir = os.path.join(workdir, "jadx-out")
        found = []
        for root, _, files in os.walk(outdir):
            for name in files:
                if not name.endswith(".java"):
                    continue
                path = os.path.join(root, name)
                with open(path, "r", encoding="utf-8") as fh:
                    if runner.FIXTURE_MARKER in fh.read():
                        found.append(os.path.relpath(path, outdir))
        self.assertTrue(
            found,
            "no .java under %s contains %r" % (
                outdir, runner.FIXTURE_MARKER))

    def test_real_many_class_evidence_bounded(self):
        # Production-APK failure shape (many-class decompile
        # overflowing the evidence cap) through the REAL jadx +
        # REAL runner collection path. The twin DEX is generated on
        # demand (not a committed binary); N=1500 overflows the
        # pre-fix collector, so this test is meaningful.
        fixtures = os.path.join(self.here, "..", "fixtures")
        sys.path.insert(0, os.path.abspath(fixtures))
        try:
            import gen_dex_many
        finally:
            sys.path.pop(0)
        twin = os.path.join(self.tmp, "twin.dex")
        with open(twin, "wb") as fh:
            fh.write(gen_dex_many.build(1500))
        workdir = os.path.join(self.tmp, "work")
        os.makedirs(workdir)
        argv = runner.build_argv("jadx", "apk", {"threads": 2},
                                workdir, twin)
        proc = subprocess.run(
            argv, stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            cwd=workdir, timeout=600,
            env=runner.tool_env("jadx", workdir))
        self.assertEqual(proc.returncode, 0, "real jadx failed")
        outdir = os.path.join(workdir, "jadx-out")
        javas = [os.path.join(r, f)
                 for r, _, fs in os.walk(outdir) for f in fs
                 if f.endswith(".java")]
        self.assertGreaterEqual(len(javas), 1500)
        r = runner.Runner("jadx", self.tmp, self.tmp)
        manifest, inline, truncated, budget = r._collect_tree(outdir)
        evidence = {"schema": runner.SCHEMA, "tool": "jadx",
                    "exit_code": 0, "profile": "apk",
                    "files": manifest, "inline": inline,
                    "truncated": truncated, "budget": budget}
        self.assertLessEqual(len(runner._dumps(evidence)),
                             runner.MAX_EVIDENCE_BYTES)
        self.assertTrue(truncated)
        self.assertGreaterEqual(budget["files_seen"], 1500)
        self.assertTrue(any("Widget0.java" in m["path"]
                            for m in manifest))
        self.assertTrue(len(inline) > 0)
        # Real-tool HTML markers survive collection byte-exact (the
        # coordinator exempts bulk source content from markup
        # rejection while escaping every render).
        marked = [t for t in inline.values()
                  if "<svg" in t or "javascript:" in t
                  or "<script" in t]
        self.assertTrue(len(marked) > 100)
        self.assertEqual(
            budget["files_seen"],
            budget["manifest_retained"] + budget["manifest_omitted"])
        claimdir = os.path.join(self.tmp, "claim")
        os.makedirs(claimdir)
        r._write_evidence(claimdir, evidence)  # must not raise
        self.assertLessEqual(
            os.path.getsize(os.path.join(claimdir, "evidence.json")),
            runner.MAX_EVIDENCE_BYTES)
        # The retained archive keeps what the bounded evidence
        # sampled: every real jadx output file, with honest
        # coverage, from the same real tree.
        meta = r._archive_tree(outdir, claimdir, "apk", 0)
        blob = os.path.join(claimdir, "output.zip")
        self.assertTrue(os.path.isfile(blob))
        with open(blob, "rb") as fh:
            digest = hashlib.sha256(fh.read()).hexdigest()
        self.assertEqual(digest, meta["sha256"])
        self.assertEqual(meta["files"], meta["files_seen"])
        self.assertGreaterEqual(meta["files"], 1500)
        self.assertFalse(meta["truncated"])
        import zipfile
        with zipfile.ZipFile(blob) as zf:
            names = zf.namelist()
            self.assertTrue(any(n.endswith("Widget0.java")
                               for n in names))
            self.assertIn("rev2square-output.json", names)
            provenance = json.loads(
                zf.read("rev2square-output.json").decode("utf-8"))
        self.assertEqual(provenance["coverage"]["files_archived"],
                         meta["files"])
        self.assertFalse(provenance["coverage"]["truncated"])

    def test_real_fixture_archive_complete(self):
        # Fast real-tier archive proof on the smoke fixture: real
        # jadx output (sources/ + resources/) archives completely
        # with the FixtureSmoketest class inside.
        workdir = os.path.join(self.tmp, "zipwork")
        os.makedirs(workdir)
        argv = runner.build_argv("jadx", "apk", {"threads": 1},
                                workdir, self.fixture)
        proc = subprocess.run(
            argv, stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            cwd=workdir, timeout=300,
            env=runner.tool_env("jadx", workdir))
        self.assertEqual(proc.returncode, 0, "real jadx failed")
        outdir = os.path.join(workdir, "jadx-out")
        r = runner.Runner("jadx", self.tmp, self.tmp)
        claimdir = os.path.join(self.tmp, "zipclaim")
        os.makedirs(claimdir)
        meta = r._archive_tree(outdir, claimdir, "apk", 0)
        self.assertFalse(meta["truncated"])
        self.assertEqual(meta["files"], meta["files_seen"])
        import zipfile
        with zipfile.ZipFile(
                os.path.join(claimdir, "output.zip")) as zf:
            names = zf.namelist()
            javas = [n for n in names if n.endswith(".java")]
            self.assertTrue(javas)
            found = any(runner.FIXTURE_MARKER in zf.read(n).decode(
                "utf-8", "replace") for n in javas)
            self.assertTrue(
                found, "no archived .java contains %r (entries: %r)"
                % (runner.FIXTURE_MARKER, names[:10]))

    def test_real_scoping_survives_readonly_home(self):
        # Production runs on a read-only root where the
        # passwd-derived user home is unwritable; with the runner's
        # per-job JADX_*_DIR scoping the decompile must still
        # succeed. Simulated by pointing user.home at a read-only
        # dir via JADX_OPTS (jadx ignores $HOME).
        home = os.path.join(self.tmp, "ro-home")
        os.makedirs(home)
        os.chmod(home, 0o555)
        workdir = os.path.join(self.tmp, "work")
        os.makedirs(workdir)
        argv = runner.build_argv("jadx", "apk", {"threads": 1},
                                workdir, self.fixture)
        env = runner.tool_env("jadx", workdir)
        env["JADX_OPTS"] = "-Duser.home=%s" % home
        proc = subprocess.run(
            argv, stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            cwd=workdir, timeout=300, env=env)
        self.assertEqual(
            proc.returncode, 0,
            "scoped jadx failed under read-only home: %r"
            % proc.stdout[:2000].decode("utf-8", "replace"))


class RealGhidraCase(unittest.TestCase):
    """REAL-EXECUTABLE ghidra probe tests (not stubs).

    Runs the actual pinned Ghidra 12.2 analyzeHeadless through the
    REAL runner version path (usage-run execution proof plus
    application.properties version). Skipped unless
    REAL_GHIDRA_HEADLESS points at an executable launcher with a
    working JVM (a skip here is an honest "not run", never a
    green). analyzeHeadless has no --version flag: the usage run
    exits 1 by design, so the execution proof is the usage marker,
    not the exit code.
    """

    @classmethod
    def setUpClass(cls):
        cls.headless = os.environ.get("REAL_GHIDRA_HEADLESS", "")
        if not (cls.headless and os.path.isfile(cls.headless)
                and os.access(cls.headless, os.X_OK)):
            raise unittest.SkipTest(
                "REAL_GHIDRA_HEADLESS not set to an executable launcher")

    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="real-ghidra-")
        self.old_env = dict(os.environ)
        os.environ["GHIDRA_HEADLESS"] = self.headless

    def tearDown(self):
        os.environ.clear()
        os.environ.update(self.old_env)
        shutil.rmtree(self.tmp, ignore_errors=True)

    def test_real_ghidra_version_via_runner(self):
        r = runner.Runner("ghidra", self.tmp, self.tmp)
        self.assertEqual(r.tool_version(), "12.2")

    def test_real_ghidra_usage_run_proves_execution(self):
        # The usage run exits 1 (no --version flag exists); what
        # proves execution is the headless main's usage marker on
        # combined output — the exact signal the runner matches.
        proc = subprocess.run(
            [self.headless], stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            timeout=120)
        self.assertIn(b"Headless Analyzer Usage:",
                      proc.stdout[:8192])
        home = os.path.dirname(os.path.dirname(
            os.path.abspath(self.headless)))
        with open(os.path.join(home, "Ghidra",
                               "application.properties")) as fh:
            text = fh.read()
        self.assertIn("application.version=12.2", text)


if __name__ == "__main__":
    unittest.main()
