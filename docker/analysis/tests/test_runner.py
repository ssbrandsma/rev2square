#!/usr/bin/env python3
"""Isolated-runner protocol tests (stdlib unittest, no pytest needed).

Verifies docker/analysis/runner.py against stub TOOL binaries: exact
argv shapes per profile, claim/lock semantics, timeout + cancel with
complete process-tree termination, input verification, output caps,
and symlink-escape rejection. Arch-independent: safe to run on any
machine with python3 (CIs, x86 laptops); native ARM64 qualification
of the REAL tools happens on the owner server (see delivery doc).

Run:  cd docker/analysis && python3 -m unittest discover -s tests -v
"""

import hashlib
import json
import os
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import unittest

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
        self.assertEqual(argv[1:4],
                         ["/w/ghidra-project", "-import", "/w/input.bin"])
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
            [jadx, "--no-src-save-cache", "-j", "2",
             "-d", "/w/jadx-out", "/w/input.bin"])
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
            "mkdir -p \"$5\"/sources/com\n"
            "echo 'class Foo{}' > \"$5\"/sources/com/Foo.java\n"
            "ln -s /etc/passwd \"$5\"/sources/escape\n"
            "mkdir -p \"$5\"/deep/a/b/c/d/e/f/g/h/i/j/k/l\n"
            "echo deep > \"$5\"/deep/a/b/c/d/e/f/g/h/i/j/k/l/x.txt\n")
        # jadx argv: [bin --no-src-save-cache -j N -d OUT IN] → OUT is $5
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


if __name__ == "__main__":
    unittest.main()
