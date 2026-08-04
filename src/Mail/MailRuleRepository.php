<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

use BvlionBatch5\Database\ConnectionFactory;
use PDO;

class MailRuleRepository
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
    ) {
    }

    /**
     * @return list<string>
     */
    public function findEnabledTargets(): array
    {
        $statement = $this->connectionFactory->create()->query(
            <<<'SQL'
                SELECT
                    target_from
                FROM mail_api
                WHERE enable_flag = 1
                ORDER BY id
                SQL,
        );

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return list<array{
     *     target_from: string,
     *     to_folder: string,
     *     channel_id: string
     * }>
     */
    public function findEnabledRules(): array
    {
        $statement = $this->connectionFactory->create()->query(
            <<<'SQL'
                SELECT
                    target_from,
                    to_folder,
                    channel_id
                FROM mail_api
                WHERE enable_flag = 1
                ORDER BY id
                SQL,
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
