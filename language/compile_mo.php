<?php
/**
 * Compile .po files to .mo binary format using pure PHP.
 * Based on the gettext .mo format specification.
 *
 * Usage: php compile_mo.php
 */

function parsePo($poFile)
{
    $content = file_get_contents($poFile);
    $entries = [];
    $currentMsgid = null;
    $currentMsgstr = null;
    $inMsgid = false;
    $inMsgstr = false;

    foreach (explode("\n", $content) as $line) {
        $line = trim($line);

        if (empty($line) || $line[0] === '#') {
            if ($currentMsgid !== null && $currentMsgstr !== null) {
                $entries[$currentMsgid] = $currentMsgstr;
            }
            $currentMsgid = null;
            $currentMsgstr = null;
            $inMsgid = false;
            $inMsgstr = false;
            continue;
        }

        if (strpos($line, 'msgid ') === 0) {
            if ($currentMsgid !== null && $currentMsgstr !== null) {
                $entries[$currentMsgid] = $currentMsgstr;
            }
            $currentMsgid = '';
            $inMsgid = true;
            $inMsgstr = false;
            $str = substr($line, 6);
            $currentMsgid .= stripcslashes(trim($str, '"'));
        } elseif (strpos($line, 'msgstr ') === 0) {
            $currentMsgstr = '';
            $inMsgid = false;
            $inMsgstr = true;
            $str = substr($line, 7);
            $currentMsgstr .= stripcslashes(trim($str, '"'));
        } elseif ($line[0] === '"') {
            $str = stripcslashes(trim($line, '"'));
            if ($inMsgid) {
                $currentMsgid .= $str;
            } elseif ($inMsgstr) {
                $currentMsgstr .= $str;
            }
        }
    }
    if ($currentMsgid !== null && $currentMsgstr !== null) {
        $entries[$currentMsgid] = $currentMsgstr;
    }

    return $entries;
}

function compileMo($entries, $moFile)
{
    // Separate header
    $headerStr = isset($entries['']) ? $entries[''] : '';
    unset($entries['']);

    // Sort by msgid (required for binary search in .mo format)
    ksort($entries);

    // Re-add header as first entry
    $sortedEntries = ['' => $headerStr] + $entries;

    $nstrings = count($sortedEntries);
    $msgids = array_keys($sortedEntries);
    $msgstrs = array_values($sortedEntries);

    // Calculate offsets
    $headerSize = 28;
    $origTableOffset = $headerSize;
    $transTableOffset = $origTableOffset + $nstrings * 8;
    $stringsStart = $transTableOffset + $nstrings * 8;

    $origStrings = [];
    $transStrings = [];
    $offset = $stringsStart;

    foreach ($msgids as $msgid) {
        $origStrings[] = [strlen($msgid), $offset];
        $offset += strlen($msgid) + 1;
    }
    foreach ($msgstrs as $msgstr) {
        $transStrings[] = [strlen($msgstr), $offset];
        $offset += strlen($msgstr) + 1;
    }

    $fh = fopen($moFile, 'wb');

    // Magic number (little endian)
    fwrite($fh, pack('V', 0x950412de));
    // Revision
    fwrite($fh, pack('V', 0));
    // Number of strings
    fwrite($fh, pack('V', $nstrings));
    // Offset of original strings table
    fwrite($fh, pack('V', $origTableOffset));
    // Offset of translated strings table
    fwrite($fh, pack('V', $transTableOffset));
    // Hash table size
    fwrite($fh, pack('V', 0));
    // Hash table offset
    fwrite($fh, pack('V', 0));

    // Original strings table
    foreach ($origStrings as $entry) {
        fwrite($fh, pack('V', $entry[0]));
        fwrite($fh, pack('V', $entry[1]));
    }

    // Translated strings table
    foreach ($transStrings as $entry) {
        fwrite($fh, pack('V', $entry[0]));
        fwrite($fh, pack('V', $entry[1]));
    }

    // String data
    foreach ($msgids as $msgid) {
        fwrite($fh, $msgid . "\0");
    }
    foreach ($msgstrs as $msgstr) {
        fwrite($fh, $msgstr . "\0");
    }

    fclose($fh);
    return $nstrings;
}

$langDir = __DIR__ . '/';

// Compile fr.mo
$entries = parsePo($langDir . 'fr.po');
$n = compileMo($entries, $langDir . 'fr.mo');
echo "Compiled fr.mo ($n entries)\n";

// Compile en.mo
$entries = parsePo($langDir . 'en.po');
$n = compileMo($entries, $langDir . 'en.mo');
echo "Compiled en.mo ($n entries)\n";

echo "Done!\n";
