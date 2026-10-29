<?php
// One search box for the whole collection: books, non-book items, academic researches and serial titles.
require __DIR__ . '/includes/bootstrap.php';
$pageTitle = 'Search';
$pdo = db();
$q = trim($_GET['q'] ?? ''); $by = $_GET['by'] ?? 'any'; $in = $_GET['in'] ?? 'all';
$byLabels = ['any' => 'Anything', 'title' => 'Title', 'author' => 'Author', 'call' => 'Call number', 'acc' => 'Accession no.', 'isbn' => 'ISBN'];
$inLabels = ['all' => 'Everything', 'books' => 'Books', 'av' => 'Non-book', 'research' => 'Files', 'serials' => 'Serials'];
if (!isset($byLabels[$by])) $by = 'any'; if (!isset($inLabels[$in])) $in = 'all';
$limit = 40;

// column used for each search type, per source
$cols = [
    'books'    => ['title' => ['Title'], 'author' => ['Author'], 'call' => ['Call_Number'], 'acc' => ['Accession'], 'isbn' => ['ISBN'], 'any' => ['Title', 'Author', 'Publisher', 'Call_Number', 'Accession', 'ISBN']],
    'av'       => ['title' => ['Title'], 'author' => ['Author'], 'call' => ['Call_Number'], 'acc' => ['Accession'], 'isbn' => [], 'any' => ['Title', 'Author', 'Publisher', 'Call_Number', 'Accession', 'Type']],
    'research' => ['title' => ['Title'], 'author' => ['Author'], 'call' => ['Call_Number'], 'acc' => ['Accession'], 'isbn' => [], 'any' => ['Title', 'Author', 'Adviser', 'Call_Number', 'Accession', 'Department', 'Type']],
    'serials'  => ['title' => ['Serial_Title'], 'author' => [], 'call' => [], 'acc' => ['Code'], 'isbn' => ['ISSN'], 'any' => ['Serial_Title', 'Code', 'ISSN', 'Subject']],
];
$tables = ['books' => 'AcList', 'av' => 'AccSM', 'research' => 'file_record', 'serials' => 'Serial_TitleTemp'];
$labels = ['books' => 'Books', 'av' => 'Non-book materials', 'research' => 'Files and researches', 'serials' => 'Serial titles'];
$results = [];
if ($q !== '') {
    foreach ($tables as $k => $tbl) {
        if ($in !== 'all' && $in !== $k) continue;
        if (!table_exists($tbl)) continue;
        $cs = $cols[$k][$by]; if (!$cs) continue;
        $like = []; $args = [];
        foreach ($cs as $i => $c) { $like[] = "`$c` LIKE :q$i"; $args["q$i"] = "%$q%"; }
        $w = '(' . implode(' OR ', $like) . ')';
        if (!lib_all()) {   // a user tied to one library only searches that library
            if ($k === 'books') { $w .= ' AND `Location` LIKE :lib'; $args['lib'] = user_library() . '%'; }
            elseif ($k === 'research' || $k === 'serials') { $w .= ' AND `Library` = :lib'; $args['lib'] = user_library(); }
        }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM `$tbl` WHERE $w"); $cnt->execute($args);
        $st = $pdo->prepare("SELECT * FROM `$tbl` WHERE $w ORDER BY " . ($k === 'serials' ? 'Serial_Title' : 'Title') . " LIMIT $limit"); $st->execute($args);
        $rows = $st->fetchAll();
        $out = [];
        $onLoan = [];
        if ($k === 'books' || $k === 'av') {
            $accs = array_column($rows, 'Accession');
            if ($accs) {
                $in_ = implode(',', array_fill(0, count($accs), '?'));
                $s2 = $pdo->prepare("SELECT Accession_number, Due_date FROM Borrower WHERE Date_returned IS NULL AND Accession_number IN ($in_)"); $s2->execute($accs);
                foreach ($s2->fetchAll() as $b) $onLoan[$b['Accession_number']] = $b['Due_date'];
            }
        }
        foreach ($rows as $r) {
            if ($k === 'serials') $out[] = [$r['Serial_Title'], $r['Frequency'] ?? '', $r['Code'] ?? '', $r['Subject'] ?? '', '', $r['ID']];
            else {
                $stat = isset($onLoan[$r['Accession']]) ? 'On loan, due ' . fdate($onLoan[$r['Accession']]) : (($k === 'books' || $k === 'av') ? 'Available' : '');
                $out[] = [$r['Title'], $r['Author'] ?? '', $r['Call_Number'] ?? '', $r['Location'] ?? ($r['Type'] ?? ''), $stat, $r['Accession']];
            }
        }
        $results[$k] = ['total' => (int)$cnt->fetchColumn(), 'rows' => $out];
    }
}
include __DIR__ . '/includes/header.php'; ?>
<h1>Search</h1>
<form class="toolbar" method="get">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Type what you are looking for…" autofocus required>
  <select name="by" aria-label="Search by"><?php foreach ($byLabels as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === $by ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select name="in" aria-label="Search in"><?php foreach ($inLabels as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === $in ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <button class="btn primary">Search</button>
</form>
<?php if ($q === ''): ?><p class="muted">Search by title, author, call number, accession number or ISBN. Use the second box to look in only one part of the collection.</p>
<?php else: $any = array_sum(array_column($results, 'total')); ?>
  <p class="muted"><?= number_format($any) ?> match<?= $any === 1 ? '' : 'es' ?> for “<?= e($q) ?>”.</p>
  <?php foreach ($results as $k => $r): if (!$r['total']) continue; ?>
    <h2><?= e($labels[$k]) ?> <small class="muted">· <?= number_format($r['total']) ?><?= $r['total'] > $limit ? ' (showing first ' . $limit . ')' : '' ?></small></h2>
    <div class="table-wrap" style="margin-bottom:18px"><table class="data"><thead><tr>
      <?php if ($k === 'serials'): ?><th>Title</th><th>Frequency</th><th>Code</th><th>Subject</th>
      <?php else: ?><th>Title</th><th>Author</th><th>Call number</th><th><?= $k === 'research' ? 'Kind of file' : 'Location' ?></th><th>Accession</th><?php if ($k !== 'research'): ?><th>Availability</th><?php endif; ?><?php endif; ?></tr></thead><tbody>
    <?php foreach ($r['rows'] as $row): ?><tr>
      <?php if ($k === 'serials'): ?><td><a href="serials.php?v=issues&t=<?= (int)$row[5] ?>"><?= e($row[0]) ?></a></td><td><?= e($row[1]) ?></td><td><?= e($row[2]) ?></td><td><?= e($row[3]) ?></td>
      <?php else: ?><td><?= e(shorten($row[0], 90)) ?></td><td><?= e(shorten($row[1], 40)) ?></td><td><?= e($row[2]) ?></td><td><?= e(shorten($row[3], 34)) ?></td><td><?= e($row[5]) ?></td>
        <?php if ($k !== 'research'): ?><td><span class="pill <?= strncmp($row[4], 'On loan', 7) === 0 ? 'inv-out' : 'inv-on-shelf' ?>"><?= e($row[4]) ?></span></td><?php endif; ?><?php endif; ?></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endforeach; if (!$any): ?><div class="card">Nothing found. Try fewer words, or search by “Anything”.</div><?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
