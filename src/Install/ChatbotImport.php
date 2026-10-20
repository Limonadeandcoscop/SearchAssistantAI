<?php
declare(strict_types=1);

namespace SearchAssistantAI\Install;

use Doctrine\DBAL\Connection;

/**
 * Imports the data of the former "Chatbot" module (1.x, or 2.0 before the
 * rename) when Search Assistant AI is installed on the same site.
 *
 * Settings `chatbot_*` are copied to `search_assistant_*`; a table is
 * copied into its new name when the new one is still empty. Nothing of the
 * old module is changed or deleted, so it can be uninstalled afterwards
 * (its uninstall drops its own tables).
 */
final class ChatbotImport
{
    public const OLD_PREFIX = 'chatbot_';
    public const NEW_PREFIX = 'search_assistant_';

    /** Module ids of the former modules; they must be inactive during the import. */
    public const OLD_MODULES = ['Chatbot', 'ChatbotPro'];

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Copy every `chatbot_*` setting whose `search_assistant_*` key is not
     * set yet. Before 2.0 the LLM provider defaulted to Claude: a site that
     * never chose one gets `claude` stored, so the import does not switch
     * it to the new default.
     *
     * @return int Number of settings written
     */
    public function importSettings(): int
    {
        $old = $this->connection->fetchAllAssociative(
            "SELECT id, value FROM setting WHERE id LIKE 'chatbot!_%' ESCAPE '!'"
        );
        if ($old === []) {
            return 0;
        }

        $existing = array_flip($this->connection->fetchFirstColumn(
            "SELECT id FROM setting WHERE id LIKE 'search!_assistant!_%' ESCAPE '!'"
        ));
        $rows = [];
        foreach ($old as $row) {
            $rows[self::NEW_PREFIX . substr((string) $row['id'], strlen(self::OLD_PREFIX))] = $row['value'];
        }
        $rows += [self::NEW_PREFIX . 'llm_provider' => json_encode('claude')];

        $written = 0;
        foreach ($rows as $id => $value) {
            if (isset($existing[$id])) {
                continue;
            }
            $this->connection->insert('setting', ['id' => $id, 'value' => $value]);
            ++$written;
        }
        return $written;
    }

    /**
     * Copy the rows of $oldTable into $newTable, for the columns both have,
     * when $oldTable exists and $newTable is empty.
     *
     * @return int Number of rows copied
     */
    public function copyTable(string $oldTable, string $newTable): int
    {
        $schema = $this->connection->getSchemaManager();
        if (!$schema->tablesExist([$oldTable]) || !$schema->tablesExist([$newTable])) {
            return 0;
        }
        if ((int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$newTable}") > 0) {
            return 0;
        }

        $columns = array_values(array_intersect(
            array_map('strtolower', array_keys($schema->listTableColumns($newTable))),
            array_map('strtolower', array_keys($schema->listTableColumns($oldTable)))
        ));
        if ($columns === []) {
            return 0;
        }
        $list = implode(', ', $columns);
        return (int) $this->connection->executeStatement(
            "INSERT INTO {$newTable} ({$list}) SELECT {$list} FROM {$oldTable}"
        );
    }

    /**
     * Former module ids that are still active on the site.
     *
     * @param callable(string): bool $isActive
     * @return list<string>
     */
    public static function activeOldModules(callable $isActive): array
    {
        return array_values(array_filter(self::OLD_MODULES, $isActive));
    }
}
