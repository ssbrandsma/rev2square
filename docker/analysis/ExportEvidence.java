// Exports bounded deterministic analysis evidence as JSON for Rev2Square.
// Runs as a Ghidra headless post-script; takes no interactive input.
//
// Args: <output.json> <base: 0xHEX> <entry: 0xHEX | none>
// Exit: 0 with evidence.json written, or an exception (runner fails closed).
//
// Ghidra 12.2 API surface used (verified against the 12.2 tag):
//   GhidraScript#run/analyzeAll/getScriptArgs, DecompInterface
//   (openProgram/decompileFunction/dispose), FunctionManager,
//   ReferenceManager, Memory/blocks, Listing/CodeUnit, TaskMonitor.
// The Dockerfile build smoke-tests script *presence*; compilation is
// verified by the owner qualification run (see delivery doc).

import java.io.FileWriter;
import java.util.ArrayList;
import java.util.Iterator;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import ghidra.app.decompiler.DecompInterface;
import ghidra.app.decompiler.DecompileResults;
import ghidra.app.decompiler.DecompiledFunction;
import ghidra.app.script.GhidraScript;
import ghidra.framework.Application;
import ghidra.program.model.address.Address;
import ghidra.program.model.address.AddressSetView;
import ghidra.program.model.listing.CodeUnit;
import ghidra.program.model.listing.Function;
import ghidra.program.model.listing.FunctionIterator;
import ghidra.program.model.listing.Instruction;
import ghidra.program.model.listing.Listing;
import ghidra.program.model.listing.Program;
import ghidra.program.model.mem.Memory;
import ghidra.program.model.mem.MemoryBlock;
import ghidra.program.model.symbol.SourceType;

public class ExportEvidence extends GhidraScript {

	private static final int MAX_FUNCTIONS = 500;
	private static final int MAX_CALLS_PER_FUNCTION = 50;
	private static final int MAX_INSTRUCTIONS = 20000;
	private static final int MAX_DECOMPILED_FUNCTIONS = 50;
	private static final int MAX_DECOMPILED_CHARS = 200 * 1024;
	private static final int MAX_LINE_CHARS = 200;
	private static final int DECOMPILE_TIMEOUT_SECS = 30;

	@Override
	public void run() throws Exception {
		String[] args = getScriptArgs();
		if (args.length < 3) {
			throw new IllegalArgumentException(
				"usage: ExportEvidence.java <output.json> <base 0xHEX> <entry 0xHEX|none>");
		}
		String outputPath = args[0];
		long base = parseHex(args[1], "base");
		boolean hasEntry = !"none".equalsIgnoreCase(args[2]);
		long entryOff = hasEntry ? parseHex(args[2], "entry") : 0;

		Program program = currentProgram;
		if (program == null) {
			throw new IllegalStateException("no current program");
		}

		List<String> warnings = new ArrayList<>();
		int tx = program.startTransaction("rev2-export");
		boolean committed = false;
		try {
			// Raw imports land at the loader default: shift every block
			// by (base - firstStart) so addresses match the target config.
			long delta = shiftBlocksToBase(program, base, warnings);

			analyzeAll(program);

			Long entryAddr = null;
			if (hasEntry) {
				Address ea = program.getAddressFactory()
					.getDefaultAddressSpace()
					.getAddress(entryOff + addressBias(program, base, delta));
				entryAddr = ea.getOffset();
				disassemble(ea);
				try {
					program.getFunctionManager()
						.createFunction("entry", ea, null, SourceType.USER_DEFINED);
				}
				catch (Exception e) {
					warnings.add("entry_function_create_failed");
				}
			}

			Map<String, Object> evidence = new LinkedHashMap<>();
			evidence.put("schema", 1);
			evidence.put("tool", "ghidra");
			evidence.put("tool_version", Application.getApplicationVersion());
			evidence.put("language", program.getLanguageID().getIdAsString());
			evidence.put("compiler_spec",
				program.getCompilerSpec().getCompilerSpecID().getIdAsString());
			evidence.put("image_base", "0x" + Long.toHexString(base));
			if (entryAddr != null) {
				evidence.put("entry", "0x" + Long.toHexString(entryAddr));
			}
			evidence.put("memory", memoryBlocks(program));
			evidence.put("functions", functions(program, warnings));
			evidence.put("xrefs", xrefs(program, warnings));
			evidence.put("disassembly",
				disassembly(program, warnings));
			evidence.put("decompilation",
				decompilation(program, warnings));
			evidence.put("warnings", warnings);

			try (FileWriter w = new FileWriter(outputPath)) {
				w.write(toJson(evidence));
			}
			committed = true;
		}
		finally {
			program.endTransaction(tx, committed);
		}
	}

	// -- address handling -------------------------------------------------

	private long parseHex(String s, String label) {
		String t = s.startsWith("0x") || s.startsWith("0X") ? s.substring(2) : s;
		try {
			return Long.parseUnsignedLong(t, 16);
		}
		catch (NumberFormatException e) {
			throw new IllegalArgumentException("bad hex " + label + ": " + s);
		}
	}

	private long shiftBlocksToBase(Program program, long base,
			List<String> warnings) throws Exception {
		Memory mem = program.getMemory();
		MemoryBlock[] blocks = mem.getBlocks();
		if (blocks.length == 0) {
			warnings.add("no_memory_blocks");
			return 0;
		}
		long first = blocks[0].getStart().getOffset();
		long delta = base - first;
		if (delta != 0) {
			for (MemoryBlock b : blocks) {
				Address to = b.getStart().getNewAddress(
					b.getStart().getOffset() + delta);
				mem.moveBlock(b, to, monitor);
			}
			warnings.add("rebased_by_0x" + Long.toHexString(delta));
		}
		return delta;
	}

	private long addressBias(Program program, long base, long delta) {
		// Entry offsets in the job spec are absolute target addresses;
		// after the shift they already match program addresses.
		return 0;
	}

	// -- evidence sections ------------------------------------------------

	private List<Map<String, Object>> memoryBlocks(Program program) {
		List<Map<String, Object>> out = new ArrayList<>();
		for (MemoryBlock b : program.getMemory().getBlocks()) {
			Map<String, Object> m = new LinkedHashMap<>();
			m.put("name", b.getName());
			m.put("start", "0x" + Long.toHexString(b.getStart().getOffset()));
			m.put("size", b.getSize());
			m.put("execute", b.isExecute());
			m.put("initialized", b.isInitialized());
			out.add(m);
			if (out.size() >= 64) {
				break;
			}
		}
		return out;
	}

	private List<Function> listedFunctions(Program program) {
		List<Function> out = new ArrayList<>();
		FunctionIterator it =
			program.getFunctionManager().getFunctions(true);
		while (it.hasNext() && out.size() < MAX_FUNCTIONS) {
			out.add(it.next());
		}
		return out;
	}

	private List<Map<String, Object>> functions(Program program,
			List<String> warnings) {
		List<Map<String, Object>> out = new ArrayList<>();
		for (Function f : listedFunctions(program)) {
			Map<String, Object> m = new LinkedHashMap<>();
			m.put("name", f.getName());
			m.put("entry",
				"0x" + Long.toHexString(f.getEntryPoint().getOffset()));
			AddressSetView body = f.getBody();
			m.put("size", body.getNumAddresses());
			out.add(m);
		}
		return out;
	}

	private List<Map<String, Object>> xrefs(Program program,
			List<String> warnings) {
		List<Map<String, Object>> out = new ArrayList<>();
		for (Function f : listedFunctions(program)) {
			long from = f.getEntryPoint().getOffset();
			int n = 0;
			try {
				for (Function callee : f.getCalledFunctions(monitor)) {
					if (n++ >= MAX_CALLS_PER_FUNCTION) {
						break;
					}
					Map<String, Object> e = new LinkedHashMap<>();
					e.put("from", "0x" + Long.toHexString(from));
					e.put("to", "0x" + Long.toHexString(
						callee.getEntryPoint().getOffset()));
					e.put("kind", "call");
					out.add(e);
					if (out.size() >= 10000) {
						warnings.add("xrefs_capped");
						return out;
					}
				}
			}
			catch (Exception e) {
				warnings.add("calls_failed_for_0x" + Long.toHexString(from));
			}
		}
		return out;
	}

	private Map<String, Object> disassembly(Program program,
			List<String> warnings) {
		List<Map<String, Object>> lines = new ArrayList<>();
		Listing listing = program.getListing();
		int count = 0;
		boolean truncated = false;
		for (MemoryBlock b : program.getMemory().getBlocks()) {
			if (!b.isExecute() || !b.isInitialized()) {
				continue;
			}
			Iterator<CodeUnit> it = listing.getCodeUnits(
				b.getAddressRange(), true).iterator();
			while (it.hasNext()) {
				CodeUnit cu = it.next();
				if (!(cu instanceof Instruction)) {
					continue;
				}
				Instruction ins = (Instruction) cu;
				if (count >= MAX_INSTRUCTIONS) {
					truncated = true;
					break;
				}
				Map<String, Object> l = new LinkedHashMap<>();
				l.put("address", "0x" + Long.toHexString(
					ins.getAddress().getOffset()));
				l.put("bytes", bytesHex(ins.getBytes()));
				String text = ins.toString();
				l.put("text", text.length() > MAX_LINE_CHARS
					? text.substring(0, MAX_LINE_CHARS) : text);
				lines.add(l);
				count++;
			}
			if (truncated) {
				break;
			}
		}
		if (truncated) {
			warnings.add("disassembly_capped");
		}
		Map<String, Object> m = new LinkedHashMap<>();
		m.put("lines", lines);
		m.put("total_instructions", count);
		m.put("truncated", truncated);
		return m;
	}

	private Map<String, Object> decompilation(Program program,
			List<String> warnings) {
		List<Map<String, Object>> funcs = new ArrayList<>();
		DecompInterface decomp = new DecompInterface();
		int chars = 0;
		int done = 0;
		boolean capped = false;
		try {
			if (!decomp.openProgram(program)) {
				warnings.add("decompiler_unavailable");
				return result(funcs, capped);
			}
			for (Function f : listedFunctions(program)) {
				if (done >= MAX_DECOMPILED_FUNCTIONS
					|| chars >= MAX_DECOMPILED_CHARS) {
					capped = true;
					break;
				}
				Map<String, Object> m = new LinkedHashMap<>();
				m.put("name", f.getName());
				m.put("entry", "0x" + Long.toHexString(
					f.getEntryPoint().getOffset()));
				try {
					DecompileResults res = decomp.decompileFunction(
						f, DECOMPILE_TIMEOUT_SECS, monitor);
					if (res != null && res.decompileCompleted()) {
						DecompiledFunction df = res.getDecompiledFunction();
						String c = df == null ? "" : df.getC();
						if (c == null) {
							c = "";
						}
						if (chars + c.length() > MAX_DECOMPILED_CHARS) {
							c = c.substring(0,
								Math.max(0, MAX_DECOMPILED_CHARS - chars));
							capped = true;
						}
						m.put("c", c);
						chars += c.length();
					}
					else {
						m.put("error", res == null ? "no-result"
							: String.valueOf(res.getErrorMessage()));
					}
				}
				catch (Exception e) {
					m.put("error", e.getClass().getSimpleName());
				}
				funcs.add(m);
				done++;
			}
		}
		finally {
			decomp.dispose();
		}
		if (capped) {
			warnings.add("decompilation_capped");
		}
		return result(funcs, capped);
	}

	private Map<String, Object> result(List<Map<String, Object>> funcs,
			boolean capped) {
		Map<String, Object> m = new LinkedHashMap<>();
		m.put("functions", funcs);
		m.put("truncated", capped);
		return m;
	}

	private String bytesHex(byte[] bytes) {
		StringBuilder sb = new StringBuilder(bytes.length * 2);
		for (byte b : bytes) {
			sb.append(Character.forDigit((b >> 4) & 0xF, 16));
			sb.append(Character.forDigit(b & 0xF, 16));
		}
		return sb.toString();
	}

	// -- minimal deterministic JSON ---------------------------------------

	private String toJson(Object o) {
		if (o == null) {
			return "null";
		}
		if (o instanceof String) {
			return "\"" + escape((String) o) + "\"";
		}
		if (o instanceof Boolean || o instanceof Number) {
			return o.toString();
		}
		if (o instanceof Map) {
			StringBuilder sb = new StringBuilder("{");
			boolean first = true;
			for (Map.Entry<?, ?> e : ((Map<?, ?>) o).entrySet()) {
				if (!first) {
					sb.append(",");
				}
				first = false;
				sb.append("\"").append(escape(String.valueOf(e.getKey())))
					.append("\":").append(toJson(e.getValue()));
			}
			return sb.append("}").toString();
		}
		if (o instanceof List) {
			StringBuilder sb = new StringBuilder("[");
			boolean first = true;
			for (Object v : (List<?>) o) {
				if (!first) {
					sb.append(",");
				}
				first = false;
				sb.append(toJson(v));
			}
			return sb.append("]").toString();
		}
		return "\"" + escape(o.toString()) + "\"";
	}

	private String escape(String s) {
		StringBuilder sb = new StringBuilder(s.length() + 8);
		for (int i = 0; i < s.length(); i++) {
			char c = s.charAt(i);
			switch (c) {
				case '"':
					sb.append("\\\"");
					break;
				case '\\':
					sb.append("\\\\");
					break;
				case '\n':
					sb.append("\\n");
					break;
				case '\r':
					sb.append("\\r");
					break;
				case '\t':
					sb.append("\\t");
					break;
				default:
					if (c < 0x20) {
						sb.append(String.format("\\u%04x", (int) c));
					}
					else {
						sb.append(c);
					}
			}
		}
		return sb.toString();
	}
}
