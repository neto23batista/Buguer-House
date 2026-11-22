<?php

declare(strict_types=1);

final class MySqlSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public const GC_BATCH_SIZE = 500;
    private ?PDO $db = null;
    private ?string $lockedKey = null;

    public function __construct(
        private readonly Closure $connect,
        private readonly int $ttlSeconds = 43200,
        private readonly int $lockSeconds = 5,
    ) {}

    private function connection(): PDO
    {
        return $this->db ??= ($this->connect)();
    }

    private function key(string $id): string
    {
        return hash('sha256', $id);
    }

    private function lock(string $id): void
    {
        $key = $this->key($id);
        if ($this->lockedKey === $key) {
            return;
        }
        $this->close();
        // MySQL named locks are connection-scoped and independent of order transactions.
        $database = (string) $this->connection()->query('SELECT DATABASE()')->fetchColumn();
        $lockName = 'bh-session:' . substr(hash('sha256', $database . ':' . $key), 0, 53);
        $query = $this->connection()->prepare('SELECT GET_LOCK(?, ?)');
        $query->execute([$lockName, $this->lockSeconds]);
        if ((int) $query->fetchColumn() !== 1) {
            throw new RuntimeException('A sessão está ocupada. Tente novamente.');
        }
        $this->lockedKey = $key;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        if ($this->lockedKey !== null && $this->db !== null) {
            $database = (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
            $lockName = 'bh-session:' . substr(hash('sha256', $database . ':' . $this->lockedKey), 0, 53);
            $query = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $query->execute([$lockName]);
            $this->lockedKey = null;
        }
        return true;
    }

    public function read(string $id): string|false
    {
        $this->lock($id);
        $query = $this->connection()->prepare('SELECT payload FROM web_sessions WHERE session_id = ? AND expires_at > UTC_TIMESTAMP()');
        $query->execute([$this->key($id)]);
        $payload = $query->fetchColumn();
        return $payload === false ? '' : (string) $payload;
    }

    public function write(string $id, string $data): bool
    {
        $this->lock($id);
        $query = $this->connection()->prepare(
            'INSERT INTO web_sessions (session_id, payload, expires_at) VALUES (?, ?, TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP()))
             ON DUPLICATE KEY UPDATE payload = ?, expires_at = TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP())',
        );
        return $query->execute([$this->key($id), $data, $this->ttlSeconds, $data, $this->ttlSeconds]);
    }

    public function destroy(string $id): bool
    {
        $this->lock($id);
        $query = $this->connection()->prepare('DELETE FROM web_sessions WHERE session_id = ?');
        $query->execute([$this->key($id)]);
        return $this->close();
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->connection()->exec(
            'DELETE FROM web_sessions WHERE expires_at <= UTC_TIMESTAMP() ORDER BY expires_at LIMIT ' . self::GC_BATCH_SIZE,
        );
    }

    public function validateId(string $id): bool
    {
        $query = $this->connection()->prepare('SELECT 1 FROM web_sessions WHERE session_id = ? AND expires_at > UTC_TIMESTAMP()');
        $query->execute([$this->key($id)]);
        return $query->fetchColumn() !== false;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }
}
