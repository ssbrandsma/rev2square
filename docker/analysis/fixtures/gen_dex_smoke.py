#!/usr/bin/env python3
"""Build dex-smoke.dex: a minimal STRUCTURALLY VALID Dalvik file.

The jadx runner decompiles this fixture on every capability probe and
only reports fixture_ok when the known class marker appears in the
output, so "jadx ready" means "demonstrated a real decompile", not
"answered a version ping".

Layout (all little-endian): header, string_ids[2], type_ids[2],
one public class_def (Lcom/rev2square/FixtureSmoketest; extends
Ljava/lang/Object;, no fields/methods), string_data, 4-byte empty
class_data, and a complete map_list. Checksum (adler32) and signature
(sha1) are computed per the DEX spec, and main() parse-back-verifies
every offset before writing the file.

Usage: python3 gen_dex_smoke.py   # writes dex-smoke.dex next to itself
"""

import hashlib
import os
import struct
import sys
import zlib

CLASS_DESC = "Lcom/rev2square/FixtureSmoketest;"
OBJECT_DESC = "Ljava/lang/Object;"
MARKER = "FixtureSmoketest"

# DEX map item types.
T_HEADER, T_STRING_ID, T_TYPE_ID, T_CLASS_DEF = 0x0000, 0x0001, 0x0002, 0x0006
T_MAP_LIST, T_CLASS_DATA, T_STRING_DATA = 0x1000, 0x2000, 0x2002


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
    raw = text.encode("ascii")
    return uleb128(len(raw)) + raw + b"\x00"


def build():
    s0 = string_data_item(CLASS_DESC)
    s1 = string_data_item(OBJECT_DESC)
    class_data = b"\x00\x00\x00\x00"  # 0 static/instance fields, 0 direct/virtual methods

    off_string_ids = 0x70
    off_type_ids = off_string_ids + 2 * 4
    off_class_defs = off_type_ids + 2 * 4
    off_data = off_class_defs + 32
    off_s0 = off_data
    off_s1 = off_s0 + len(s0)
    pad = (-(off_s1 + len(s1))) % 4
    off_class_data = off_s1 + len(s1) + pad
    off_map = off_class_data + len(class_data)

    blob = bytearray()
    blob += b"dex\n035\x00"          # magic
    blob += b"\x00" * 4              # checksum placeholder
    blob += b"\x00" * 20             # signature placeholder
    blob += struct.pack("<20I", 0,   # file_size placeholder
                        0x70, 0x12345678, 0, 0, off_map,
                        2, off_string_ids, 2, off_type_ids,
                        0, 0, 0, 0, 0, 0,
                        1, off_class_defs, 0, off_data)
    assert len(blob) == 0x70
    blob += struct.pack("<2I", off_s0, off_s1)          # string_ids
    blob += struct.pack("<2I", 0, 1)                   # type_ids -> string_idx
    blob += struct.pack("<8I", 0, 0x1, 1, 0, 0xFFFFFFFF, 0, off_class_data, 0)  # class_def
    assert len(blob) == off_data
    blob += s0 + s1 + b"\x00" * pad + class_data
    assert len(blob) == off_map
    entries = [(T_HEADER, 1, 0x0), (T_STRING_ID, 2, off_string_ids),
               (T_TYPE_ID, 2, off_type_ids), (T_CLASS_DEF, 1, off_class_defs),
               (T_MAP_LIST, 1, off_map), (T_CLASS_DATA, 1, off_class_data),
               (T_STRING_DATA, 2, off_s0)]
    blob += struct.pack("<I", len(entries))
    for itype, size, off in sorted(entries):
        blob += struct.pack("<HHII", itype, 0, size, off)

    struct.pack_into("<I", blob, 8 + 24, len(blob))    # file_size at 0x20
    data_size_off = 0x20 + 18 * 4  # 19th uint32 of the header block
    struct.pack_into("<I", blob, data_size_off, len(blob) - off_data)
    # Signature first: adler32 covers file[12:], which includes it.
    blob[12:32] = hashlib.sha1(bytes(blob[32:])).digest()
    struct.pack_into("<I", blob, 8, zlib.adler32(bytes(blob[12:])) & 0xFFFFFFFF)
    return bytes(blob)


def verify(blob):
    assert blob[:8] == b"dex\n035\x00", "magic"
    (file_size, header_size, endian) = struct.unpack_from("<3I", blob, 0x20)
    assert file_size == len(blob), "file_size"
    assert header_size == 0x70 and endian == 0x12345678, "header"
    (adler,) = struct.unpack_from("<I", blob, 8)
    assert adler == zlib.adler32(blob[12:]) & 0xFFFFFFFF, "adler32"
    assert blob[12:32] == hashlib.sha1(blob[32:]).digest(), "sha1"
    (sns, so, tns, to, _, _, _, _, _, _, cns, co, ds, do) = struct.unpack_from("<14I", blob, 0x38)
    assert (sns, tns, cns) == (2, 2, 1), "section counts"
    assert so + 8 <= len(blob) and to + 8 <= len(blob) and co + 32 <= len(blob), "table bounds"
    sids = struct.unpack_from("<2I", blob, so)
    tids = struct.unpack_from("<2I", blob, to)
    assert all(o < len(blob) for o in sids) and list(tids) == [0, 1], "id tables"
    assert blob[sids[0] + 1:sids[0] + 1 + len(CLASS_DESC)].decode() == CLASS_DESC, "class desc"
    (cidx, acc, sup, _, _, _, cdo, _) = struct.unpack_from("<8I", blob, co)
    assert (cidx, acc, sup) == (0, 0x1, 1) and blob[cdo:cdo + 4] == b"\0\0\0\0", "class_def"
    (moff,) = struct.unpack_from("<I", blob, 0x34)
    (count,) = struct.unpack_from("<I", blob, moff)
    assert count == 7 and moff + 4 + 12 * count == len(blob), "map_list"
    assert do + ds == len(blob), "data span"


def main():
    blob = build()
    verify(blob)
    here = os.path.dirname(os.path.abspath(__file__))
    with open(os.path.join(here, "dex-smoke.dex"), "wb") as handle:
        handle.write(blob)
    print(f"dex-smoke.dex: {len(blob)} bytes sha256={hashlib.sha256(blob).hexdigest()}")


if __name__ == "__main__":
    sys.exit(main())
