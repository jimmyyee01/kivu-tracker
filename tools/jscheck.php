<?php
/* =====================================================================
   KIVU Tracker — tools/jscheck.php
   Dependency-free JavaScript sanity checker (there is no Node on this
   machine): strips comments, strings, template literals and regex
   literals, then verifies that every bracket is balanced and that no
   string/comment is left unterminated. Catches the mistakes that would
   otherwise only show up in the browser console.

       php tools/jscheck.php              # checks public/js/*.js
       php tools/jscheck.php file1.js ...
   ===================================================================== */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$files = array_slice($argv, 1);
if (!$files) {
    $files = glob(dirname(__DIR__) . '/public/js/*.js') ?: [];
    sort($files);
}
if (!$files) { fwrite(STDERR, "No JavaScript files found.\n"); exit(1); }

$problems = 0;
foreach ($files as $file) {
    $src = (string)file_get_contents($file);
    $len = strlen($src);
    $stack = [];
    $line = 1;
    $issues = [];
    $state = 'code';
    $lastSignificant = '';
    $tail = '';
    $code = '';                       // code-only copy (comments/strings blanked) for regex checks
    for ($i = 0; $i < $len; $i++) {
        $c = $src[$i];
        $next = $i + 1 < $len ? $src[$i + 1] : '';
        if ($c === "\n") $line++;
        switch ($state) {
            case 'code':
                if ($c === '/' && $next === '/') { $state = 'line'; $i++; break; }
                if ($c === '/' && $next === '*') { $state = 'block'; $i++; break; }
                if ($c === "'" ) { $state = 'single'; break; }
                if ($c === '"') { $state = 'double'; break; }
                if ($c === '`') { $state = 'template'; break; }
                /* a '/' starts a regex after an operator or a keyword such as return */
                $afterKeyword = preg_match(
                    '#(?:^|[^\w$])(return|typeof|case|in|of|instanceof|new|delete|void|do|else|yield|await)\s*$#',
                    $tail);
                if ($c === '/' && (preg_match('#^[=(,:\[!&|?{};\s\n]$#', $lastSignificant) || $afterKeyword)) {
                    $state = 'regex';
                    break;
                }
                if (strpos('([{', $c) !== false) { $stack[] = [$c, $line]; }
                elseif (strpos(')]}', $c) !== false) {
                    $open = array_pop($stack);
                    $want = [')' => '(', ']' => '[', '}' => '{'][$c];
                    if ($open === null) $issues[] = "line $line: unexpected '$c'";
                    elseif ($open[0] !== $want) $issues[] = "line $line: '$c' closes '{$open[0]}' opened on line {$open[1]}";
                }
                if (!ctype_space($c)) $lastSignificant = $c;
                $tail .= $c;
                if (strlen($tail) > 40) $tail = substr($tail, -40);
                break;
            case 'line':
                if ($c === "\n") $state = 'code';
                break;
            case 'block':
                if ($c === '*' && $next === '/') { $state = 'code'; $i++; }
                break;
            case 'single':
                if ($c === '\\') { $i++; }
                elseif ($c === "'") $state = 'code';
                elseif ($c === "\n") { $issues[] = "line $line: unterminated string"; $state = 'code'; }
                break;
            case 'double':
                if ($c === '\\') { $i++; }
                elseif ($c === '"') $state = 'code';
                elseif ($c === "\n") { $issues[] = "line $line: unterminated string"; $state = 'code'; }
                break;
            case 'template':
                if ($c === '\\') { $i++; }
                elseif ($c === '`') $state = 'code';
                break;
            case 'regex':
                if ($c === '\\') { $i++; }
                elseif ($c === '/') $state = 'code';
                elseif ($c === "\n") { $issues[] = "line $line: unterminated regex"; $state = 'code'; }
                break;
        }
        /* keep a parallel copy that only contains real code, so the checks
           below cannot be confused by a '(' inside a string or a comment */
        $code .= $state === 'code' ? $c : ($c === "\n" ? "\n" : ' ');
    }
    if ($state !== 'code' && $state !== 'line') $issues[] = "file ends inside a $state block";
    foreach ($stack as $open) $issues[] = "'{$open[0]}' opened on line {$open[1]} is never closed";
    /* a declaration can never be followed by '(' — `const formModal({` parses
       in the browser as "Missing initializer in const declaration" and kills
       the whole file, which a bracket check alone cannot see. */
    if (preg_match_all('/\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => $hit) {
            $badLine = substr_count(substr($code, 0, (int)$hit[1]), "\n") + 1;
            $issues[] = "line $badLine: '" . $m[1][$i][0] . "' is declared without an initialiser"
                . ' — replace "' . trim($hit[0]) . '" with "' . $m[1][$i][0] . '("';
        }
    }
    if ($issues) {
        $problems++;
        echo 'FAIL  ' . basename($file) . "\n";
        foreach (array_slice($issues, 0, 8) as $issue) echo '        ' . $issue . "\n";
    } else {
        printf("OK    %-16s %5d lines\n", basename($file), substr_count($src, "\n") + 1);
    }
}
echo $problems === 0 ? "\nAll JavaScript files look balanced.\n" : "\n$problems file(s) with problems.\n";
exit($problems === 0 ? 0 : 1);
