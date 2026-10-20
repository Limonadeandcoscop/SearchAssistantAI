<?php
/**
 * Ad-hoc smoke test for the LLM streaming path (queryStream).
 *
 * Bootstraps Omeka from inside the container and asks the configured LLM
 * provider (gateway / Claude / Ollama, per admin settings) for a short
 * streamed completion, printing each delta with its arrival time.
 *
 * Usage: docker exec omeka-s php /var/www/html/modules/SearchAssistantAI/test/scripts/stream-smoke.php
 */
declare(strict_types=1);

define('OMEKA_PATH', '/var/www/html');
require '/var/www/html/vendor/autoload.php';

$application = \Omeka\Mvc\Application::init(
    require '/var/www/html/application/config/application.config.php'
);
$services = $application->getServiceManager();

/** @var \SearchAssistantAI\Service\Llm\LlmInterface $llm */
$llm = $services->get('SearchAssistantAI\Service\Llm\LlmInterface');
echo 'LLM service: ' . get_class($llm) . "\n";

$start = microtime(true);
$deltaCount = 0;
$firstDeltaAt = null;

// Fake search context so the collections-only system prompt lets the
// model produce a long, streamed answer.
$fakeResults = [[
    'id' => '73',
    'score' => 0.9,
    'payload' => [
        'title' => 'Histoire de Pascal Paoli. Tome I',
        'creator' => 'Arrighi, Arrigo',
        'date' => '1843',
        'type' => 'item',
        'description' => "Biographie de Pascal Paoli (1725-1807), général et homme d'État corse, "
            . "chef de la Corse indépendante de 1755 à 1769, auteur de la constitution corse, "
            . "exilé en Angleterre après Ponte-Novo, revenu pendant la Révolution française.",
    ],
]];

$full = $llm->queryStream(
    'Explique en environ 150 mots qui était Pascal Paoli et son rôle dans l\'histoire de la Corse.',
    $fakeResults,
    [],
    function (string $chunk) use ($start, &$deltaCount, &$firstDeltaAt): void {
        $deltaCount++;
        if ($firstDeltaAt === null) {
            $firstDeltaAt = microtime(true) - $start;
            printf("[%6.2fs] first delta: %s\n", $firstDeltaAt, json_encode($chunk, JSON_UNESCAPED_UNICODE));
        } elseif ($deltaCount % 25 === 0) {
            printf("[%6.2fs] delta #%d\n", microtime(true) - $start, $deltaCount);
        }
    }
);

printf(
    "[%6.2fs] done — %d deltas, %d chars, first delta at %s\n",
    microtime(true) - $start,
    $deltaCount,
    strlen($full),
    $firstDeltaAt === null ? 'n/a' : sprintf('%.2fs', $firstDeltaAt)
);
echo "---\n" . $full . "\n";
