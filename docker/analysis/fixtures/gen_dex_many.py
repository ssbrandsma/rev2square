#!/usr/bin/env python3
"""Build a many-class STRUCTURALLY VALID Dalvik file for evidence testing.

Unlike dex-smoke.dex (one empty class), this emits N classes each with
one tiny static method, so real jadx runs produce megabytes of
multi-file output: the shape that overflowed the runner evidence cap
on the production APK (4327 classes). A subset of class names uses
non-ASCII identifiers to exercise UTF-8 + JSON escaping growth.

Layout (all little-endian): header, string_ids, type_ids, one shared
()V proto, N method_ids, N class_defs, N class_data items, N code
items (return-void), string_data, map_list. Checksum (adler32) and
signature (sha1) per the DEX spec; main() parse-back-verifies offsets.

Usage: python3 gen_dex_many.py [N]   # writes dex-many.dex next to itself
"""

import hashlib
import os
import struct
import sys
import zlib

OBJECT_DESC = "Ljava/lang/Object;"
VOID_DESC = "V"
METHOD_NAME = "ping"

T_HEADER, T_STRING_ID, T_TYPE_ID = 0x0000, 0x0001, 0x0002
T_PROTO_ID, T_METHOD_ID, T_CLASS_DEF = 0x0003, 0x0005, 0x0006
T_MAP_LIST, T_CLASS_DATA, T_CODE = 0x1000, 0x2000, 0x2001
T_STRING_DATA = 0x2002


def uleb128(value):
    out = bytearray()
    while True:
        byte = value & 0x7F
        value >>= 7
        if value:
            out.append(byte | 0x80)
        else:
            out.append(byte)
            return bytes(out)


def string_data_item(text):
    raw = text.encode("utf-8")
    utf16_len = len(text.encode("utf-16-le")) // 2
    return uleb128(utf16_len) + raw + b"\x00"


def class_desc(i):
    # Every 7th class carries non-ASCII identifiers (escaping growth).
    if i % 7 == 3:
        return "Lcom/twin/\u00dcn\u00efc\u00f6de\u0394%d;" % i
    if i % 11 == 5:
        return "Lcom/twin/\u6d4b\u8bd5\u7c7b%d;" % i
    return "Lcom/twin/pkg%02d/Widget%d;" % (i % 64, i)


def build(nclasses):
    descs = [class_desc(i) for i in range(nclasses)]
    strings = [OBJECT_DESC, VOID_DESC, METHOD_NAME] + descs
    # type_ids: 0=Object, 1=void, 2..=classes.
    sitems = [string_data_item(s) for s in strings]
    # One shared ()V proto; N method_ids (class_idx varies).
    # class_data per class: 0 static, 0 instance, 1 direct, 0 virtual.
    code_item = struct.pack("<4HI", 0, 0, 0, 0, 0) + struct.pack("<I", 1)
    code_item += struct.pack("<H", 0x000E)  # return-void (op in low byte)
    assert len(code_item) == 18

    off_string_ids = 0x70
    off_type_ids = off_string_ids + len(strings) * 4
    off_proto_ids = off_type_ids + (nclasses + 2) * 4
    off_method_ids = off_proto_ids + 12
    off_class_defs = off_method_ids + nclasses * 8
    off_data = off_class_defs + nclasses * 32

    # Data section: class_data items, code items (4-aligned), strings.
    # code_off ULEB sizes depend on the layout itself: iterate to a
    # fixpoint (sizes only grow into wider ULEBs a bounded number
    # of times).
    # Diff encoding resets per class_data: each class's single method
    # carries its absolute method index.
    method_idx_diffs = list(range(nclasses))
    guesses = [0] * nclasses
    laid = []
    for _ in range(10):
        chunks = []
        for i in range(nclasses):
            chunks.append((1, uleb128(0) + uleb128(0) + uleb128(1)
                           + uleb128(0) + uleb128(method_idx_diffs[i])
                           + uleb128(0x9) + uleb128(guesses[i]),
                           "class_data"))
        for _ in range(nclasses):
            chunks.append((4, code_item, "code"))
        for item in sitems:
            chunks.append((1, item, "string_data"))
        off = off_data
        laid = []
        for align, data, kind in chunks:
            off += (-off) % align
            laid.append((off, data, kind))
            off += len(data)
        actual = [o for (o, _, k) in laid if k == "code"]
        if actual == guesses:
            break
        guesses = actual
    else:
        raise AssertionError("layout did not converge")
    off_map = off + (-off) % 4
    code_offs = [o for (o, _, k) in laid if k == "class_data"]
    code_bases = [o for (o, _, k) in laid if k == "code"]

    blob = bytearray()
    blob += b"dex\n035\x00"
    blob += b"\x00" * 4
    blob += b"\x00" * 20
    blob += struct.pack("<20I", 0, 0x70, 0x12345678, 0, 0, off_map,
                        len(strings), off_string_ids,
                        nclasses + 2, off_type_ids,
                        1, off_proto_ids, 0, 0,
                        nclasses, off_method_ids,
                        nclasses, off_class_defs, 0, off_data)
    assert len(blob) == 0x70
    # string_ids (offsets filled from layout).
    string_offs = [o for (o, _, k) in laid if k == "string_data"]
    assert len(string_offs) == len(strings)
    for o in string_offs:
        blob += struct.pack("<I", o)
    # type_ids -> string_idx: 0=Object, 1=void, 2+i=class i.
    blob += struct.pack("<2I", 0, 1)
    for i in range(nclasses):
        blob += struct.pack("<I", 3 + i)
    # proto: shorty_idx(V=1), return(V=1), params_off=0.
    blob += struct.pack("<III", 1, 1, 0)
    # method_ids: (class_idx, proto_idx, name_idx=2).
    for i in range(nclasses):
        blob += struct.pack("<HHI", i + 2, 0, 2)
    # class_defs: interfaces_off=0, source=NO_INDEX.
    for i in range(nclasses):
        blob += struct.pack("<8I", i + 2, 0x1, 0, 0, 0xFFFFFFFF, 0,
                            code_offs[i], 0)
    assert len(blob) == off_data
    for (o, data, kind) in laid:
        assert len(blob) <= o
        blob += b"\x00" * (o - len(blob))
        blob += data
    blob += b"\x00" * (off_map - len(blob))
    assert len(blob) == off_map
    entries = [(T_HEADER, 1, 0x0),
               (T_STRING_ID, len(strings), off_string_ids),
               (T_TYPE_ID, nclasses + 2, off_type_ids),
               (T_PROTO_ID, 1, off_proto_ids),
               (T_METHOD_ID, nclasses, off_method_ids),
               (T_CLASS_DEF, nclasses, off_class_defs),
               (T_MAP_LIST, 1, off_map),
               (T_CLASS_DATA, nclasses, code_offs[0]),
               (T_CODE, nclasses, code_bases[0]),
               (T_STRING_DATA, len(strings), string_offs[0])]
    blob += struct.pack("<I", len(entries))
    for itype, size, o in sorted(entries):
        blob += struct.pack("<HHII", itype, 0, size, o)

    struct.pack_into("<I", blob, 0x20, len(blob))
    struct.pack_into("<I", blob, 0x20 + 18 * 4, len(blob) - off_data)
    blob[12:32] = hashlib.sha1(bytes(blob[32:])).digest()
    struct.pack_into("<I", blob, 8, zlib.adler32(bytes(blob[12:])) & 0xFFFFFFFF)
    return bytes(blob)


def verify(blob, nclasses):
    assert blob[:8] == b"dex\n035\x00", "magic"
    (file_size, header_size, endian) = struct.unpack_from("<3I", blob, 0x20)
    assert file_size == len(blob) and header_size == 0x70
    assert endian == 0x12345678
    (adler,) = struct.unpack_from("<I", blob, 8)
    assert adler == zlib.adler32(blob[12:]) & 0xFFFFFFFF, "adler32"
    assert blob[12:32] == hashlib.sha1(blob[32:]).digest(), "sha1"
    (moff,) = struct.unpack_from("<I", blob, 0x34)
    (count,) = struct.unpack_from("<I", blob, moff)
    assert moff + 4 + 12 * count == len(blob), "map_list"


def main(argv):
    nclasses = int(argv[1]) if len(argv) > 1 else 3000
    blob = build(nclasses)
    verify(blob, nclasses)
    here = os.path.dirname(os.path.abspath(__file__))
    with open(os.path.join(here, "dex-many.dex"), "wb") as handle:
        handle.write(blob)
    print("dex-many.dex: %d classes, %d bytes sha256=%s"
          % (nclasses, len(blob), hashlib.sha256(blob).hexdigest()))


if __name__ == "__main__":
    sys.exit(main(sys.argv))
