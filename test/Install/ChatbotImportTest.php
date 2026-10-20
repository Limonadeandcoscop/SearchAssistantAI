<?php
declare(strict_types=1);

namespace SearchAssistantAITest\Install;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use SearchAssistantAI\Install\ChatbotImport;

class ChatbotImportTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required');
        }
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE setting (id VARCHAR(190) PRIMARY KEY, value TEXT)');
        $this->db->executeStatement('CREATE TABLE chatbot_message (id INTEGER PRIMARY KEY, content TEXT, legacy TEXT)');
        $this->db->executeStatement('CREATE TABLE search_assistant_message (id INTEGER PRIMARY KEY, content TEXT, role TEXT)');
    }

    /**
     * @return array<string, string>
     */
    private function settings(): array
    {
        $rows = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, value FROM setting ORDER BY id') as $row) {
            $rows[$row['id']] = $row['value'];
        }
        return $rows;
    }

    public function testCopiesTheOldSettingsAndKeepsThem(): void
    {
        $this->db->insert('setting', ['id' => 'chatbot_llm_provider', 'value' => '"ollama"']);
        $this->db->insert('setting', ['id' => 'chatbot_primary_color', 'value' => '"#920b0b"']);
        $this->db->insert('setting', ['id' => 'chatbotx', 'value' => '"not a chatbot_ key"']);

        self::assertSame(2, (new ChatbotImport($this->db))->importSettings());

        self::assertSame([
            'chatbot_llm_provider' => '"ollama"',
            'chatbot_primary_color' => '"#920b0b"',
            'chatbotx' => '"not a chatbot_ key"',
            'search_assistant_llm_provider' => '"ollama"',
            'search_assistant_primary_color' => '"#920b0b"',
        ], $this->settings());
    }

    public function testAnOldSiteWithoutProviderKeepsClaude(): void
    {
        $this->db->insert('setting', ['id' => 'chatbot_primary_color', 'value' => '"#920b0b"']);

        (new ChatbotImport($this->db))->importSettings();

        self::assertSame('"claude"', $this->settings()['search_assistant_llm_provider']);
    }

    public function testNothingIsWrittenWithoutOldSettings(): void
    {
        self::assertSame(0, (new ChatbotImport($this->db))->importSettings());
        self::assertSame([], $this->settings());
    }

    public function testExistingNewSettingsAreNotOverwritten(): void
    {
        $this->db->insert('setting', ['id' => 'chatbot_primary_color', 'value' => '"#920b0b"']);
        $this->db->insert('setting', ['id' => 'search_assistant_primary_color', 'value' => '"#000000"']);

        (new ChatbotImport($this->db))->importSettings();

        self::assertSame('"#000000"', $this->settings()['search_assistant_primary_color']);
    }

    public function testCopiesTheCommonColumnsIntoAnEmptyTable(): void
    {
        $this->db->insert('chatbot_message', ['id' => 7, 'content' => 'Bonjour', 'legacy' => 'x']);

        self::assertSame(1, (new ChatbotImport($this->db))->copyTable('chatbot_message', 'search_assistant_message'));

        self::assertSame(
            [['id' => 7, 'content' => 'Bonjour', 'role' => null]],
            array_map(function (array $row): array {
                $row['id'] = (int) $row['id'];
                return $row;
            }, $this->db->fetchAllAssociative('SELECT id, content, role FROM search_assistant_message'))
        );
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM chatbot_message'));
    }

    public function testDoesNotCopyIntoATableThatHasRows(): void
    {
        $this->db->insert('chatbot_message', ['id' => 7, 'content' => 'Bonjour']);
        $this->db->insert('search_assistant_message', ['id' => 1, 'content' => 'Already here']);

        self::assertSame(0, (new ChatbotImport($this->db))->copyTable('chatbot_message', 'search_assistant_message'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM search_assistant_message'));
    }

    public function testMissingOldTableIsIgnored(): void
    {
        self::assertSame(0, (new ChatbotImport($this->db))->copyTable('chatbot_sync_queue', 'search_assistant_message'));
    }

    public function testListsTheOldModulesStillActive(): void
    {
        self::assertSame(['Chatbot'], ChatbotImport::activeOldModules(function (string $id): bool {
            return $id === 'Chatbot';
        }));
    }
}
