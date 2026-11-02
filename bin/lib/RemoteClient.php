<?php
declare(strict_types=1);

namespace Loxi2;

require_once __DIR__ . '/TabletStore.php';
require_once __DIR__ . '/FleetState.php';

/** Local reported-state/desired-queue adapter. No network connection is opened to tablets. */
final class RemoteClient
{
    private FleetState $state;
    public function __construct(array $device, string $dataDirectory, ?string $ramDirectory = null)
    {
        $this->state = new FleetState($dataDirectory, $device['id'], $ramDirectory);
    }
    public function snapshot(): array { return $this->state->snapshot(); }
    public function status(): array {
        $value = $this->state->read();
        return ['ok'=>true, 'management'=>FleetState::metadata($value), 'reported'=>$value['status'] ?? []];
    }
    public function apps(): array { $value=$this->state->read(); return ['ok'=>true,'apps'=>$value['apps'] ?? []]; }
    public function diagnostics(): array { return ['ok'=>true]+$this->state->diagnostics(); }
    public function history(): array { return $this->state->history(); }
    public function saveBackup(string $revision, string $label=''): array { return $this->state->saveBackup($revision,$label); }
    public function restoreBackup(string $revision, string $backupId): array { return $this->state->restoreBackup($revision,$backupId); }
    public function exportBackup(string $backupId): array { return $this->state->exportBackup($backupId); }
    public function importBackup(string $revision, array $document): array { return $this->state->importBackup($revision,$document); }
    public function patch(string $revision, array $settings, array $actions = []): array { return $this->state->queue($revision,$settings,$actions); }
    public function control(string $revision, bool $discard): array { return $this->state->control($revision,$discard); }
    public function upload(int $id, string $name, string $extension, string $revision, string $file): array { return $this->state->stageSound($id,$name,$extension,$revision,$file); }
    public static function signature(string $token, string $method, string $path, string $timestamp, string $nonce, string $bodyHash): string
    {
        return hash_hmac('sha256', $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $bodyHash, $token);
    }
    public static function responseSignature(string $token, string $nonce, int $status, string $body): string
    {
        return self::responseHashSignature($token,$nonce,$status,hash('sha256',$body));
    }
    public static function responseHashSignature(string $token, string $nonce, int $status, string $hash): string
    {
        return hash_hmac('sha256', "RESPONSE\n" . $nonce . "\n" . $status . "\n" . $hash, $token);
    }
}
