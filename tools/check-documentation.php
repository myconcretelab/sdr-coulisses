<?php
/** Standalone PHP 8.1+ check; no WordPress load and no network requests. */
$root = dirname(__DIR__);
$mode = $argv[1] ?? '--check';
if (!in_array($mode, ['--check', '--review'], true) || count($argv) > 2) {
    fwrite(STDERR, "Usage : php tools/check-documentation.php [--check|--review]\n");
    exit(2);
}

try {
    $path = $root.'/documentation/guides.json';
    $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($data['schema'] ?? null) !== 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['updated'] ?? '') || empty($data['domains'])) throw new RuntimeException('Structure des guides invalide.');
    $ids = []; $count = 0;
    foreach ($data['domains'] as $domain) {
        if (empty($domain['title']) || empty($domain['summary']) || empty($domain['guides'])) throw new RuntimeException('Domaine incomplet.');
        foreach (array_merge([$domain], $domain['guides']) as $entry) {
            $id = $entry['id'] ?? '';
            if (!is_string($id) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $id) || isset($ids[$id])) throw new RuntimeException('Identifiant absent, invalide ou dupliqué : '.$id);
            $ids[$id] = true;
        }
        foreach ($domain['guides'] as $guide) {
            if (empty($guide['title']) || empty($guide['summary']) || empty($guide['steps']) || !is_array($guide['steps']) || !is_array($guide['notes'] ?? null)) throw new RuntimeException('Guide incomplet : '.$guide['id']);
            if (!in_array($guide['access'] ?? '', ['all', 'organizer', 'administrator'], true)) throw new RuntimeException('Accès invalide : '.$guide['id']);
            foreach (array_merge($guide['steps'], $guide['notes']) as $text) if (!is_string($text) || trim($text) === '') throw new RuntimeException('Texte vide dans '.$guide['id']);
            if (isset($guide['reference']) && !in_array($guide['reference'], ['sdr_event', 'sdr_venue'], true)) throw new RuntimeException('Référence de champs inconnue.');
            $count++;
        }
    }
    $sources = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        if (preg_match('~^(?:\.git|tests|vendor|node_modules)/~', $relative)) continue;
        if (!preg_match('/\.(?:php|js|css)$/', $relative) && !str_ends_with($relative, '/block.json')) continue;
        $sources[$relative] = hash_file('sha256', $file->getPathname());
    }
    ksort($sources);
    $reviewPath = $root.'/documentation/review.json';
    if ($mode === '--review') {
        // This command records an explicit review. It does not invent new procedures.
        $data['updated'] = gmdate('Y-m-d');
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        $review = ['schema' => 1, 'reviewed' => $data['updated'], 'guides' => hash_file('sha256', $path), 'sources' => $sources];
        file_put_contents($reviewPath, json_encode($review, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        echo "Relecture enregistrée : $count guides. Les champs de référence sont générés par le site.\n";
    } else {
        $review = is_file($reviewPath) ? json_decode(file_get_contents($reviewPath), true, 512, JSON_THROW_ON_ERROR) : [];
        $previous = $review['sources'] ?? [];
        $changed = array_keys(array_diff_assoc($sources, $previous) + array_diff_assoc($previous, $sources));
        if (($review['guides'] ?? '') !== hash_file('sha256', $path) || $changed) {
            if ($changed) fwrite(STDERR, "Code modifié depuis la dernière relecture :\n - ".implode("\n - ", $changed)."\n");
            throw new RuntimeException('Mettre à jour ou relire les guides concernés, puis exécuter php tools/check-documentation.php --review et commiter les guides avec documentation/review.json.');
        }
        echo "Documentation vérifiée : $count guides, ".count($sources)." fichiers de code couverts.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Documentation : ".$error->getMessage()."\n");
    exit(1);
}
