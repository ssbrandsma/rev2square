# Binary fixtures

All binary-investigation fixtures are **generated in code** by
`tests/Support/BinaryFixtures.php` (and golden byte vectors inside
`tests/Unit/Binary/DisassemblerTest.php`). No fixture binaries are
committed to this directory, and no downloaded, proprietary, or
malware-derived bytes are used anywhere in the suite.

Provenance per builder:

| Builder | What it emits | Basis |
|---|---|---|
| `ihex` / `srec` | Intel HEX / S-record text with computed checksums | Published record formats |
| `prgBasic` / `prgMachineCode` | C64 PRG (load address + BASIC chain and/or 6502 bytes) | C64 BASIC V2 token layout |
| `code6502` / `codeZ80` / `code8051` | Hand-assembled call/return snippets | CPU manuals (opcodes cross-checked by golden tests) |
| `pe` | Minimal PE32/PE32+ with sections, imports, optional CLR header | PE/COFF structure |
| `machoThin` / `machoFat` | Mach-O 64-bit + FAT wrapper | Mach-O load-command layout |
| `dex` | Minimal DEX with string/type/method/class tables | DEX format structure |
| `axml` | Binary Android manifest string pool (UTF-8) | AXML chunk layout |
| `apk` | ZIP with manifest + DEX + native `.so` + resources | Uses the repo's existing synthetic ELF fixture for the `.so` member |
| `classFile` | Minimal Java class (major 52) | Class-file structure |
| `zip` | Generic ZIP members | — |

The `.so` member inside synthetic APKs reuses
`tests/Fixtures/Artifacts/files/elf64-x86_64.elf`, the repository's
own synthetic ELF fixture (see its README for provenance).

License: same as the repository (see `LICENSE`).
