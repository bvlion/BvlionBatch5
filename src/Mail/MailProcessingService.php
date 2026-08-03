<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

use BvlionBatch5\Slack\SlackClient;
use RuntimeException;

final class MailProcessingService
{
    public function __construct(
        private MailRuleRepository $mailRuleRepository,
        private ImapMailbox $mailbox,
        private MimeMessageDecoder $mimeMessageDecoder,
        private MailProcessingHistoryRepository $historyRepository,
        private SlackClient $slackClient,
        private string $mailboxIdentifier,
    ) {
    }

    /**
     * @return array{success: bool, failure_count: int}
     */
    public function process(): array
    {
        $rules = $this->mailRuleRepository->findEnabledRules();

        if ($rules === []) {
            return ['success' => true, 'failure_count' => 0];
        }

        $failureCount = 0;
        $matchedUids = [];
        $this->mailbox->connect(false, false);

        try {
            $uidValidity = $this->mailbox->getUidValidity();
            $messages = $this->mailbox->searchMessages();

            foreach ($rules as $rule) {
                if ($rule['target_from'] === '') {
                    continue;
                }

                foreach ($messages as $message) {
                    if (isset($matchedUids[$message['uid']])) {
                        continue;
                    }

                    if (
                        mb_stripos(
                            $message['sender'],
                            $rule['target_from'],
                        ) === false
                        && mb_stripos(
                            $message['subject'],
                            $rule['target_from'],
                        ) === false
                    ) {
                        continue;
                    }

                    $matchedUids[$message['uid']] = true;

                    try {
                        $history = $this->historyRepository->find(
                            $this->mailboxIdentifier,
                            $uidValidity,
                            $message['uid'],
                        );

                        if (($history['completed'] ?? false) === true) {
                            continue;
                        }

                        if (($history['slack_posted'] ?? false) !== true) {
                            $content = $this->mailbox->readMessage(
                                $message['uid'],
                                $this->mimeMessageDecoder,
                            );
                            $slackTimestamp = $this->slackClient->postMessage(
                                $rule['channel_id'],
                                sprintf(
                                    "件名：%s\n----------\n%s\n----------",
                                    $content['subject'],
                                    $content['body'],
                                ),
                            );
                            $this->historyRepository->recordSlackPosted(
                                $this->mailboxIdentifier,
                                $uidValidity,
                                $message['uid'],
                                $slackTimestamp,
                            );
                            $this->mailbox->markMessageAsSeen(
                                $message['uid'],
                            );
                        }

                        $this->mailbox->moveMessage(
                            $message['uid'],
                            $rule['to_folder'],
                        );
                        $this->historyRepository->markCompleted(
                            $this->mailboxIdentifier,
                            $uidValidity,
                            $message['uid'],
                        );
                    } catch (RuntimeException) {
                        $failureCount++;
                    }
                }
            }
        } finally {
            $this->mailbox->disconnect();
        }

        return [
            'success' => $failureCount === 0,
            'failure_count' => $failureCount,
        ];
    }
}
