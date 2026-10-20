<?php
/**
 * Extracts translatable strings from Search Assistant AI module and generates template.pot,
 * fr.po, and en.po files.
 *
 * Usage: php generate_pot.php
 */

$moduleDir = dirname(__DIR__);
$dirs = [
    $moduleDir . '/view/',
    $moduleDir . '/src/',
];

$strings = [];
foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $ext = $f->getExtension();
        if (!in_array($ext, ['phtml', 'php'])) continue;
        $content = file_get_contents($f->getPathname());
        // Match $translate('...') and $this->translate('...')
        if (preg_match_all("/(?:\\\$translate|\\\$this->translate)\('([^']+)'\)/", $content, $m)) {
            foreach ($m[1] as $s) {
                $strings[$s] = true;
            }
        }
    }
}
ksort($strings);
$keys = array_keys($strings);

echo "Found " . count($keys) . " unique translatable strings.\n";

// ===== French translations for English-source strings =====
$frTranslations = [
    // Admin dashboard
    'Indexation State' => 'État de l\'indexation',
    'Vector database statistics and indexation status from %s.' => 'Statistiques de la base vectorielle et état de l\'indexation depuis %s.',
    'Indexed Documents' => 'Documents indexés',
    'Indexed Items' => 'Items indexés',
    'Pending Indexation' => 'En attente d\'indexation',
    'Last Full Indexation' => 'Dernière indexation complète',
    'Warning:' => 'Attention :',
    'Some items have been pending indexation for an extended period.' => 'Certains items sont en attente d\'indexation depuis longtemps.',
    'Usage Metrics' => 'Métriques d\'utilisation',
    'Search Assistant AI usage statistics and performance analytics.' => 'Statistiques d\'utilisation de l'assistant et analyses de performance.',
    'Total Questions' => 'Total des questions',
    'Today' => 'Aujourd\'hui',
    'This Week' => 'Cette semaine',
    'This Month' => 'Ce mois-ci',
    'Avg Response Time' => 'Temps de réponse moyen',
    'Satisfaction Rate' => 'Taux de satisfaction',
    'Questions Over Time' => 'Questions au fil du temps',
    'Line chart showing search assistant question volume over time' => 'Graphique montrant le volume de questions de l'assistant au fil du temps',
    'Technical Health' => 'État technique',
    'Connection status and health monitoring for all integrated services.' => 'État de connexion et surveillance de tous les services intégrés.',
    'Vector Database' => 'Base de données vectorielle',
    'Checking...' => 'Vérification...',
    'Embeddings Service' => 'Service d\'embeddings',
    'Claude API' => 'API Claude',
    'Self-hosted LLM' => 'LLM auto-hébergé',
    'Processing...' => 'Traitement en cours...',
    'Reindex All' => 'Tout réindexer',
    'Force Sync' => 'Forcer la synchronisation',
    'Clear Cache' => 'Vider le cache',
    'Test Configuration' => 'Tester la configuration',
    'Clear %s Data' => 'Effacer les données %s',

    // Admin config
    'Interface Customization' => 'Personnalisation de l\'interface',
    'Customize the appearance and behavior of the search assistant widget on your site.' => 'Personnalisez l\'apparence et le comportement du widget de l'assistant sur votre site.',
    'characters' => 'caractères',
    'Indexing Field Selection' => 'Sélection des champs d\'indexation',
    'Select which properties to include in vector indexing. If no properties are selected, all properties will be indexed (default behavior).' => 'Sélectionnez les propriétés à inclure dans l\'indexation vectorielle. Si aucune propriété n\'est sélectionnée, toutes les propriétés seront indexées (comportement par défaut).',
    'Filter properties...' => 'Filtrer les propriétés...',
    'Select All' => 'Tout sélectionner',
    'Deselect All' => 'Tout désélectionner',
    'Expand All' => 'Tout déplier',
    'Collapse All' => 'Tout replier',
    'No properties selected' => 'Aucune propriété sélectionnée',
    'all properties will be indexed (default behavior)' => 'toutes les propriétés seront indexées (comportement par défaut)',
    'Collection Indexing' => 'Indexation des collections',
    'Select which collections (Item Sets) to include in vector indexing. If no collections are selected, all items will be indexed regardless of collection membership (default behavior). Items belonging to at least one selected collection will be indexed.' => 'Sélectionnez les collections (ensembles d\'items) à inclure dans l\'indexation vectorielle. Si aucune collection n\'est sélectionnée, tous les items seront indexés quel que soit leur appartenance (comportement par défaut). Les items appartenant à au moins une collection sélectionnée seront indexés.',
    'Filter collections...' => 'Filtrer les collections...',
    'No collections selected' => 'Aucune collection sélectionnée',
    'all items will be indexed (default behavior)' => 'tous les items seront indexés (comportement par défaut)',
    'No collections found. Items will be indexed regardless of collection membership.' => 'Aucune collection trouvée. Les items seront indexés quel que soit leur appartenance à une collection.',
    'Save Configuration' => 'Enregistrer la configuration',

    // Widget
    'Chat Assistant' => 'Assistant de chat',
    'Open chat assistant' => 'Ouvrir l\'assistant de chat',
    'Clear conversation' => 'Effacer la conversation',
    'Close chat assistant' => 'Fermer l\'assistant de chat',
    'Chat conversation' => 'Conversation du chat',
    'Type your message' => 'Tapez votre message',
    'Type your message...' => 'Tapez votre message...',
    'Message input' => 'Saisie du message',
    'Send message' => 'Envoyer le message',

    // Search Assistant AI page
    'Collection explorer and filters' => 'Explorateur de collections et filtres',
    'Breadcrumb navigation' => 'Fil d\'Ariane',
    'Open help modal' => 'Ouvrir l\'aide',
    'Close help modal' => 'Fermer l\'aide',
    'Start a new conversation' => 'Démarrer une nouvelle conversation',
    'Clear all conversation history' => 'Effacer tout l\'historique des conversations',
    'Content type filter' => 'Filtre par type de contenu',
    'Select content type' => 'Sélectionner le type de contenu',
    'Select time period' => 'Sélectionner la période',
    'Time period filter' => 'Filtre par période',
    'Chat messages' => 'Messages du chat',
    'No image available' => 'Aucune image disponible',
    'Type: %s' => 'Type : %s',
    'Relevance: %d%%' => 'Pertinence : %d%%',
    'Author:' => 'Auteur :',
    'Date:' => 'Date :',
    'View details for %s' => 'Voir les détails de %s',
    'Audio player for %s' => 'Lecteur audio pour %s',
    'Your browser does not support the audio element.' => 'Votre navigateur ne supporte pas l\'élément audio.',
    'Audio' => 'Audio',
    'Video player for %s' => 'Lecteur vidéo pour %s',
    'Your browser does not support the video element.' => 'Votre navigateur ne supporte pas l\'élément vidéo.',
    'Video' => 'Vidéo',

    // Search results
    'Results controls' => 'Contrôles des résultats',
    'Grid view' => 'Vue en grille',
    'List view' => 'Vue en liste',
    'Table view' => 'Vue en tableau',
    'View mode' => 'Mode d\'affichage',
    'Sort results by' => 'Trier les résultats par',
    'Search filters' => 'Filtres de recherche',
    'Filter by content type' => 'Filtrer par type de contenu',
    'Filter by media type' => 'Filtrer par type de média',
    'Filter by time period' => 'Filtrer par période',
    'Reset all filters' => 'Réinitialiser tous les filtres',
    'Search results' => 'Résultats de recherche',
    'Search results pagination' => 'Pagination des résultats',
    'Previous page' => 'Page précédente',
    'Next page' => 'Page suivante',

    // Reindex page
    'Configure and launch manual reindexing operations for the vector database. Choose resource types, scope, and execution mode below.' => 'Configurez et lancez des opérations de réindexation manuelle pour la base vectorielle. Choisissez les types de ressources, la portée et le mode d\'exécution ci-dessous.',
    'Current Indexation Status' => 'État actuel de l\'indexation',
    'Collection Name' => 'Nom de la collection',
    'Never' => 'Jamais',
    'Reindex Configuration' => 'Configuration de la réindexation',
    'Resource Type' => 'Type de ressource',
    'Select which type of resources to reindex.' => 'Sélectionnez le type de ressources à réindexer.',
    'Reindex Scope' => 'Portée de la réindexation',
    'Choose the scope of the reindexing operation.' => 'Choisissez la portée de l\'opération de réindexation.',
    'Resource IDs' => 'IDs de ressources',
    'Enter comma-separated resource IDs to reindex (e.g., 1, 5, 12, 45).' => 'Entrez les IDs de ressources séparés par des virgules (ex. : 1, 5, 12, 45).',
    'Execution Mode' => 'Mode d\'exécution',
    'Choose how the reindexing job should be executed.' => 'Choisissez comment la tâche de réindexation doit être exécutée.',
    'Preview' => 'Aperçu',
    'Launch Reindex' => 'Lancer la réindexation',
    'Preview Results' => 'Résultats de l\'aperçu',
    'Reindex Progress' => 'Progression de la réindexation',
    'Status' => 'Statut',
    'Progress' => 'Progression',
    'Job ID' => 'ID de la tâche',
    'Cancel Job' => 'Annuler la tâche',
    'Job Logs' => 'Journaux de la tâche',
    'Loading...' => 'Chargement...',
    'Resources to be reindexed:' => 'Ressources à réindexer :',
    'No resources found with the selected criteria.' => 'Aucune ressource trouvée avec les critères sélectionnés.',
    'Preview failed.' => 'L\'aperçu a échoué.',
    'Error during preview:' => 'Erreur pendant l\'aperçu :',
    'Complete reindex will delete all existing index entries. Are you sure?' => 'La réindexation complète supprimera toutes les entrées existantes. Êtes-vous sûr ?',
    'Launching...' => 'Lancement...',
    'Reindex job started successfully.' => 'La tâche de réindexation a démarré avec succès.',
    'Failed to launch reindex job.' => 'Échec du lancement de la tâche de réindexation.',
    'Error launching job:' => 'Erreur au lancement de la tâche :',
    'Are you sure you want to cancel the reindex job?' => 'Êtes-vous sûr de vouloir annuler la tâche de réindexation ?',
    'Job cancellation requested.' => 'Annulation de la tâche demandée.',
    'Failed to cancel job.' => 'Échec de l\'annulation de la tâche.',
    'Error canceling job:' => 'Erreur lors de l\'annulation de la tâche :',
    'Reindex completed successfully!' => 'Réindexation terminée avec succès !',
    'Reindex job failed. Check logs for details.' => 'La tâche de réindexation a échoué. Consultez les journaux pour plus de détails.',
    'Reindex job was cancelled.' => 'La tâche de réindexation a été annulée.',

    // Analytics dashboard
    'Export CSV' => 'Exporter en CSV',
    'Export JSON' => 'Exporter en JSON',
    'Refresh Data' => 'Actualiser les données',
    'Last 7 Days' => '7 derniers jours',
    'Last 30 Days' => '30 derniers jours',
    'Last 90 Days' => '90 derniers jours',
    'Last Year' => 'Année dernière',
    'All Time' => 'Tout le temps',
    'Conversation History' => 'Historique des conversations',
    'Overview of stored conversations and interaction history.' => 'Aperçu des conversations enregistrées et de l\'historique des interactions.',
    'Total Conversations' => 'Total des conversations',
    'Total Messages' => 'Total des messages',
    'Avg Messages/Conversation' => 'Moy. messages/conversation',
    'Storage Used' => 'Stockage utilisé',
    'Conversations Over Time' => 'Conversations au fil du temps',
    'Line chart showing conversation volume over time' => 'Graphique montrant le volume de conversations au fil du temps',
    'User Engagement' => 'Engagement utilisateur',
    'User interaction patterns and engagement metrics.' => 'Schémas d\'interaction des utilisateurs et métriques d\'engagement.',
    'Unique Users' => 'Utilisateurs uniques',
    'Returning Users' => 'Utilisateurs récurrents',
    'Avg Session Duration' => 'Durée moyenne de session',
    'Engagement Rate' => 'Taux d\'engagement',
    'Active Users by Hour' => 'Utilisateurs actifs par heure',
    'Bar chart showing active users by hour of day' => 'Graphique montrant les utilisateurs actifs par heure du jour',
    'Query Performance' => 'Performance des requêtes',
    'Response quality and performance metrics.' => 'Qualité des réponses et métriques de performance.',
    'Successful Queries' => 'Requêtes réussies',
    'Failed Queries' => 'Requêtes échouées',
    'Success Rate' => 'Taux de réussite',
    'Avg Tokens/Response' => 'Moy. tokens/réponse',
    'Total Tokens Used' => 'Total tokens utilisés',
    'Avg Relevance Score' => 'Score de pertinence moyen',
    'User Satisfaction' => 'Satisfaction utilisateur',
    'Response Time Distribution' => 'Distribution des temps de réponse',
    'Histogram showing distribution of response times' => 'Histogramme montrant la distribution des temps de réponse',

    // Analytics search
    'Search Conversations' => 'Rechercher des conversations',
    'Search conversation history by session ID, user ID, keyword, or date range. Results include full message history and metadata.' => 'Recherchez l\'historique des conversations par ID de session, ID utilisateur, mot-clé ou plage de dates. Les résultats incluent l\'historique complet des messages et les métadonnées.',
    'Session ID' => 'ID de session',
    'e.g., sess_abc123xyz' => 'ex. : sess_abc123xyz',
    'Exact session identifier' => 'Identifiant de session exact',
    'User ID' => 'ID utilisateur',
    'e.g., 123' => 'ex. : 123',
    'Numeric user identifier (leave empty for anonymous)' => 'Identifiant numérique de l\'utilisateur (laisser vide pour anonyme)',
    'Keyword' => 'Mot-clé',
    'Search in message content...' => 'Rechercher dans le contenu des messages...',
    'Search for text within messages' => 'Rechercher du texte dans les messages',
    'Date From' => 'Date de début',
    'Start date (inclusive)' => 'Date de début (incluse)',
    'Date To' => 'Date de fin',
    'End date (inclusive)' => 'Date de fin (incluse)',
    'Results Per Page' => 'Résultats par page',
    'Maximum results to display (max: 1000)' => 'Nombre maximal de résultats à afficher (max : 1000)',
    'Clear Filters' => 'Effacer les filtres',
    'Error:' => 'Erreur :',
    'Search Results' => 'Résultats de recherche',
    'Found %d conversation(s) matching your search criteria.' => '%d conversation(s) trouvée(s) correspondant à vos critères de recherche.',
    'No Results' => 'Aucun résultat',
    'No conversations match your search criteria. Try adjusting your filters or search terms.' => 'Aucune conversation ne correspond à vos critères de recherche. Essayez d\'ajuster vos filtres ou vos termes de recherche.',
    'Conversation ID' => 'ID de conversation',
    'Started At' => 'Démarré le',
    'Ended At' => 'Terminé le',
    'Messages' => 'Messages',
    'Actions' => 'Actions',
    'Anonymous' => 'Anonyme',
    'View Messages' => 'Voir les messages',
    'Messages for conversation %s' => 'Messages de la conversation %s',
    'Conversation Messages' => 'Messages de la conversation',
    'No messages in this conversation.' => 'Aucun message dans cette conversation.',
    'Metadata' => 'Métadonnées',
    'Page %d of %d (%d total results)' => 'Page %d sur %d (%d résultats au total)',
    'First page' => 'Première page',
    'First' => 'Premier',
    'Last page' => 'Dernière page',
    'Last' => 'Dernier',
    'Page %d' => 'Page %d',
    'Searching conversations...' => 'Recherche de conversations...',
    'Loading analytics...' => 'Chargement des analyses...',
    'Loading data...' => 'Chargement des données...',
    'Most frequently asked questions and trending topics.' => 'Questions les plus fréquemment posées et sujets tendance.',
    'Popular Topics' => 'Sujets populaires',
    'Topic Distribution' => 'Distribution des sujets',
    'Pie chart showing distribution of questions by topic' => 'Graphique montrant la distribution des questions par sujet',
    'Top 10 Questions' => 'Top 10 des questions',
    'Trending Queries (Last 7 Days)' => 'Requêtes tendance (7 derniers jours)',

    // Preview modal
    'Next' => 'Suivant',
    'Previous' => 'Précédent',
    'Date' => 'Date',
    'Images' => 'Images',
    'Documents' => 'Documents',
    'Item' => 'Item',
    'Items' => 'Items',
    'Collection' => 'Collection',
    'Collections' => 'Collections',
];

// ===== English translations for French-source strings =====
$enTranslations = [
    'Assistant de recherche' => 'Research Assistant',
    'Aller au contenu principal' => 'Skip to main content',
    'Accueil' => 'Home',
    'Search Assistant AI' => 'Search Assistant AI',
    'Aide' => 'Help',
    'Session controls' => 'Session controls',
    'Nouvelle conversation' => 'New conversation',
    'Effacer tout' => 'Clear all',
    'Explorer les collections' => 'Explore collections',
    'Type de contenu' => 'Content type',
    'Tous' => 'All',
    'Médias' => 'Media',
    'Période' => 'Period',
    'Toutes les périodes' => 'All periods',
    'XVe siècle' => '15th century',
    'XVIe siècle' => '16th century',
    'XVIIe siècle' => '17th century',
    'XVIIIe siècle' => '18th century',
    'XIXe siècle' => '19th century',
    'XXe siècle' => '20th century',
    'XXIe siècle' => '21st century',
    'Collections populaires' => 'Popular collections',
    'Peintures' => 'Paintings',
    'Photographies' => 'Photographs',
    'Sculptures' => 'Sculptures',
    'Manuscrits' => 'Manuscripts',
    'Questions suggérées' => 'Suggested questions',
    'Historique récent' => 'Recent history',
    'Aucune question récente' => 'No recent questions',
    'Maintenant' => 'Now',
    'Posez votre question...' => 'Ask your question...',
    'Comment utiliser l\'assistant' => 'How to use the assistant',
    'Utilisez les questions suggérées pour commencer' => 'Use the suggested questions to get started',
    'Filtrez par type de contenu ou période pour affiner vos résultats' => 'Filter by content type or period to refine your results',
    'Cliquez sur les collections populaires pour des suggestions thématiques' => 'Click on popular collections for themed suggestions',
    'Votre historique est conservé localement pour faciliter la navigation' => 'Your history is stored locally for easier navigation',
    'Sans titre' => 'Untitled',
    'Ensemble' => 'Item Set',
    'Ressource' => 'Resource',
    'Média' => 'Media',
    'Score de pertinence: %d%%' => 'Relevance score: %d%%',
    'Aucune description disponible' => 'No description available',
    'Voir les détails' => 'View details',
    'Appuyez sur Entrée pour ouvrir l\'aperçu ou Espace pour voir les détails' => 'Press Enter to open preview or Space to view details',
    'Aller directement aux résultats' => 'Skip to results',
    '%d résultats trouvés' => '%d results found',
    'Aucun résultat' => 'No results',
    'Grille' => 'Grid',
    'Liste' => 'List',
    'Tableau' => 'Table',
    'Trier par:' => 'Sort by:',
    'Pertinence' => 'Relevance',
    'Titre' => 'Title',
    'Filtrer les résultats' => 'Filter results',
    'Type de média' => 'Media type',
    'Vidéos' => 'Videos',
    'Réinitialiser les filtres' => 'Reset filters',
    '%d résultats de recherche' => '%d search results',
    'Précédent' => 'Previous',
    'Page %d sur %d' => 'Page %d of %d',
    'Suivant' => 'Next',
    'Aucun résultat trouvé' => 'No results found',
    'Essayez de modifier vos filtres ou votre requête de recherche.' => 'Try adjusting your filters or search query.',
    'Retour au search assistant' => 'Back to assistant',
    'Aperçu du résultat' => 'Result preview',
    'Fenêtre modale affichant l\'aperçu du résultat de recherche. Utilisez Échap pour fermer, les flèches gauche/droite pour naviguer entre les résultats.' => 'Modal window showing search result preview. Use Escape to close, left/right arrows to navigate between results.',
    'Fermer l\'aperçu (Échap)' => 'Close preview (Escape)',
    'Chargement du média...' => 'Loading media...',
    'Résultat précédent (Flèche gauche)' => 'Previous result (Left arrow)',
    'Résultat suivant (Flèche droite)' => 'Next result (Right arrow)',
    'Résultats' => 'Results',
    'Effacer tout' => 'Clear all',
    'Bonjour ! Comment puis-je vous aider à explorer nos collections aujourd\'hui ?' => 'Hello! How can I help you explore our collections today?',
    'Quels sont les horaires d\'ouverture du musée ?' => 'What are the museum opening hours?',
    'Le musée est ouvert du mardi au dimanche de 10h00 à 18h00. Nous sommes fermés le lundi et les jours fériés. Puis-je vous aider avec autre chose ?' => 'The museum is open Tuesday to Sunday from 10:00 AM to 6:00 PM. We are closed on Mondays and public holidays. Can I help you with anything else?',
    'Merci !' => 'Thank you!',
];

// === Generate POT file ===
function escapePo($s) {
    return str_replace(['"', "\n"], ['\\"', "\\n"], $s);
}

function writePoEntry($fh, $msgid, $msgstr = '') {
    $msgid = escapePo($msgid);
    $msgstr = escapePo($msgstr);
    fwrite($fh, "msgid \"$msgid\"\n");
    fwrite($fh, "msgstr \"$msgstr\"\n");
    fwrite($fh, "\n");
}

function writeHeader($fh, $language, $revision) {
    fwrite($fh, "# Search Assistant AI Module for Omeka S\n");
    fwrite($fh, "# Copyright (C) 2024 LimonadeAndCo\n");
    fwrite($fh, "#\n");
    if (empty($language)) fwrite($fh, "#, fuzzy\n");
    fwrite($fh, "msgid \"\"\n");
    fwrite($fh, "msgstr \"\"\n");
    fwrite($fh, "\"Project-Id-Version: SearchAssistantAI\\n\"\n");
    fwrite($fh, "\"Report-Msgid-Bugs-To: \\n\"\n");
    fwrite($fh, "\"POT-Creation-Date: 2026-03-27 00:00+0000\\n\"\n");
    fwrite($fh, "\"PO-Revision-Date: $revision\\n\"\n");
    if (empty($language)) {
        fwrite($fh, "\"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n\"\n");
        fwrite($fh, "\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n");
        fwrite($fh, "\"Language: \\n\"\n");
    } else {
        fwrite($fh, "\"Last-Translator: Ralph <noreply@anthropic.com>\\n\"\n");
        fwrite($fh, "\"Language-Team: $language\\n\"\n");
        fwrite($fh, "\"Language: $language\\n\"\n");
    }
    fwrite($fh, "\"MIME-Version: 1.0\\n\"\n");
    fwrite($fh, "\"Content-Type: text/plain; charset=UTF-8\\n\"\n");
    fwrite($fh, "\"Content-Transfer-Encoding: 8bit\\n\"\n");
    if ($language === 'fr') {
        fwrite($fh, "\"Plural-Forms: nplurals=2; plural=(n > 1);\\n\"\n");
    }
    fwrite($fh, "\n");
}

$langDir = dirname(__DIR__) . '/language/';

// template.pot
$fh = fopen($langDir . 'template.pot', 'w');
writeHeader($fh, '', 'YEAR-MO-DA HO:MI+ZONE');
foreach ($keys as $s) {
    writePoEntry($fh, $s);
}
fclose($fh);
echo "Written template.pot\n";

// fr.po
$fh = fopen($langDir . 'fr.po', 'w');
writeHeader($fh, 'fr', '2026-03-27 00:00+0000');
foreach ($keys as $s) {
    $tr = isset($frTranslations[$s]) ? $frTranslations[$s] : $s;
    writePoEntry($fh, $s, $tr);
}
fclose($fh);
echo "Written fr.po\n";

// en.po
$fh = fopen($langDir . 'en.po', 'w');
writeHeader($fh, 'en', '2026-03-27 00:00+0000');
foreach ($keys as $s) {
    $tr = isset($enTranslations[$s]) ? $enTranslations[$s] : $s;
    writePoEntry($fh, $s, $tr);
}
fclose($fh);
echo "Written en.po\n";

echo "Done! Now compile with: msgfmt fr.po -o fr.mo && msgfmt en.po -o en.mo\n";
