<?php

namespace App\Support;

/**
 * User-language labels for raw extraction and analysis warning
 * codes. Codes stay available as expandable diagnostics; the label
 * is what readers see first. Unknown codes fall back to a
 * humanized reading — never a blank — with the raw code kept.
 */
class WarningLabels
{
    /**
     * @var array<string, string> code => label, :detail interpolates
     *                            the code suffix (member name, index, nested error).
     */
    private const LABELS = [
        // ELF extraction.
        'bad_phentsize' => 'Program header entries have an unexpected size; some segments may be missing.',
        'truncated_segments' => 'The segment table was cut off; some segments are missing.',
        'no_section_headers' => 'No section headers; section names are unavailable.',
        'bad_shentsize' => 'Section header entries have an unexpected size.',
        'truncated_sections' => 'The section table was cut off; some sections are missing.',
        'bad_shstrndx' => 'The section name table is missing; sections are shown without names.',
        'section_out_of_bounds' => 'Section :detail points outside the file and was skipped.',
        'unsupported_machine' => 'Processor type :detail is not supported for disassembly.',
        'unsupported_arch' => 'Architecture :detail is not supported for disassembly.',
        'no_dynamic_segment' => 'No dynamic segment; library dependencies are unknown.',
        'no_symbols' => 'No symbol table; function names are unavailable.',
        'truncated_symbols' => 'The symbol table was cut off; some names are missing.',
        'truncated_dynamic' => 'The dynamic section was cut off; some dependencies may be missing.',
        // Captures.
        'capture_truncated_or_capped' => 'The capture was truncated or hit the size cap; later packets are missing.',
        'unsupported_linktype_present' => 'Some packets use an unsupported link type and were skipped.',
        // Archives and extraction.
        'entries_capped' => 'The archive listing hit the entry cap; some members are not shown.',
        'encrypted_member' => 'Member :detail is encrypted and was skipped.',
        'unsupported_method' => 'Member :detail uses an unsupported compression method and was skipped.',
        'snapshot_trimmed' => 'The extraction summary exceeded the size cap and was trimmed.',
        'extraction_capped' => 'Extraction hit the output cap; some files are not shown.',
        'extraction_fallback_used' => 'The primary extractor failed; a fallback was used.',
        'no_files_extracted' => 'No files could be extracted.',
        'member_already_present' => 'This member was already imported and was reused.',
        'first_member_unparsed' => 'The first archive member could not be parsed (:detail).',
        // DEX, APK, class, Mach-O, PE.
        'classes_capped' => 'The class listing hit the cap; some classes are not shown.',
        'methods_capped' => 'The method listing hit the cap; some methods are not shown.',
        'pool_capped' => 'A constant pool listing hit the cap; some entries are not shown.',
        'dex_members_capped' => 'The DEX member listing hit the cap.',
        'dex_unreadable' => ':Detail could not be read as DEX.',
        'so_unreadable' => 'Native library :detail could not be read.',
        'so_members_capped' => 'The native library listing hit the cap.',
        'fat_members_capped' => 'The fat binary member listing hit the cap.',
        'fat_member_out_of_bounds' => 'Fat binary member :detail points outside the file and was skipped.',
        'resources_arsc_not_decoded' => 'Application resources were not decoded; only the manifest and code were read.',
        'manifest_unreadable' => 'The application manifest could not be read.',
        'manifest_unparsed' => 'The application manifest could not be parsed.',
        'possible_split_package' => 'This looks like a split package; some parts may live in sibling files.',
        'sections_capped' => 'The section listing hit the cap.',
        'imports_exports_capped' => 'The import and export listing hit the cap.',
        'commands_capped' => 'The load command listing hit the cap.',
        'unsupported_cputype' => 'CPU type :detail is not supported.',
        'entry_unmapped' => 'The entry point has no file offset; disassembly starts without it.',
        // Firmware records.
        'strings_capped' => 'The string listing hit the cap; some strings are not shown.',
        'records_capped' => 'The record listing hit the cap.',
        'data_capped' => 'The data listing hit the size cap; later bytes are not shown.',
        'overlapping_records' => ':Detail overlapping records were found; the first occurrence was kept.',
        'unsupported_record_type' => 'Record type :detail is not supported and was skipped.',
        'bad_checksums' => ':Detail records failed checksum validation and were skipped.',
        // C64 programs.
        'basic_chain_broken' => 'BASIC line links are broken; the listing may be incomplete.',
        'no_basic_structure' => 'No BASIC program structure was found; treated as machine code.',
        // Analysis output budgets.
        'reconstruction_capped' => 'Reconstruction hit the output budget; some files are listed without content.',
        'disassembly_capped' => 'Disassembly hit the output budget; some instructions were omitted.',
        'runner_output_capped' => 'Tool output hit the size cap; some output was omitted.',
        'unparsed_output_lines' => 'Some tool output lines could not be parsed and were skipped.',
        'xrefs_capped' => 'The cross-reference table hit the cap; some references are missing.',
        'output_archive_discarded' => 'The downloadable archive could not be retained; the bounded evidence below still stands.',
        'evidence_trimmed' => 'Evidence exceeded the storage cap; disassembly detail was shed while findings were kept.',
        'no_types_reconstructed' => 'No types were reconstructed from this assembly.',
    ];

    /**
     * @var array<string, string> analysis failure codes => concise
     *                            user language. Used only when no specific message was
     *                            recorded; recorded messages are already user language.
     */
    private const ERROR_LABELS = [
        'evidence_too_large' => 'Analysis output exceeded the storage cap.',
        'evidence_rejected' => 'Analysis output failed validation.',
        'configuration_required' => 'This file needs configuration before analysis.',
        'target_unsupported' => 'The selected target is not supported for this file.',
        'spool_unavailable' => 'The isolated analysis worker is unavailable.',
        'no_executable_segments' => 'No executable code was found to analyze.',
        'runner_failed' => 'The analysis tool failed.',
        'extraction_failed' => 'Extraction inside the analysis worker failed.',
        'analysis_timeout' => 'Analysis timed out.',
        'environmental' => 'Analysis failed unexpectedly.',
        'job_failed' => 'Analysis did not finish.',
        'resource_exhausted' => 'Analysis ran out of resources.',
        'backend_crash' => 'The analysis backend crashed.',
        'backend_timeout' => 'The analysis backend timed out.',
        'cancelled_race' => 'The run was cancelled.',
        'missing_original' => 'The original file is missing from storage.',
        'storage_unreadable' => 'Storage is temporarily unreadable.',
        'member_base_required' => 'This archive member needs a base address.',
        'member_empty' => 'This archive member is empty.',
        'member_encrypted' => 'This archive member is encrypted.',
        'member_format_unsupported' => 'This archive member format is not supported.',
        'member_not_found' => 'This archive member was not found.',
        'member_target_mismatch' => 'The selected target contradicts this member.',
        'member_target_required' => 'This archive member needs an explicit target.',
        'member_too_large' => 'This archive member exceeds the size cap.',
        'member_unparseable' => 'This archive member does not parse.',
        'member_unreadable' => 'The archive directory does not parse.',
        'member_unsupported_method' => 'This archive member uses an unsupported compression method.',
    ];

    /**
     * Split a stored warning into its code and optional detail
     * suffix (member name, index, nested error).
     *
     * @return array{code: string, detail: string|null}
     */
    public static function split(string $warning): array
    {
        if (preg_match('/^([a-z][a-z0-9_]*):(.*)$/', $warning, $m) === 1) {
            return ['code' => $m[1], 'detail' => $m[2] === '' ? null : $m[2]];
        }

        return ['code' => $warning, 'detail' => null];
    }

    /**
     * Concise user language for a stored warning. Sentence-style
     * warnings (repository imports) pass through untouched.
     */
    public static function label(string $warning): string
    {
        ['code' => $code, 'detail' => $detail] = self::split($warning);

        $template = self::LABELS[$code] ?? null;
        if ($template === null) {
            // Not a code-shaped warning: keep the recorded text.
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
                return $warning;
            }

            // Unknown code: humanize, keep the raw code findable.
            $template = ucfirst(str_replace('_', ' ', $code)).'.';
        }

        if ($detail !== null) {
            if (str_contains($template, ':detail')) {
                return str_replace(':detail', $detail, $template);
            }

            return $template.' ('.$detail.')';
        }

        return str_replace(' :detail', '', str_replace(':detail', '—', $template));
    }

    /**
     * Concise user language for an analysis failure code, or null
     * when the code is unknown.
     */
    public static function errorLabel(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::ERROR_LABELS[$code] ?? null;
    }
}
