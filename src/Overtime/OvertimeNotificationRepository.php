<?php

declare(strict_types=1);

namespace BvlionBatch5\Overtime;

use BvlionBatch5\Database\ConnectionFactory;
use PDO;

class OvertimeNotificationRepository
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
    ) {
    }

    /**
     * @return array{message: string, channel_id: string}|null
     */
    public function find(): ?array
    {
        $statement = $this->connectionFactory->create()->query(
            <<<'SQL'
                SELECT
                    message,
                    channel_id
                FROM overtime_notification_settings
                WHERE id = 1
                SQL,
        );
        $notificationConfiguration = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($notificationConfiguration)
            ? $notificationConfiguration
            : null;
    }
}
