<?php

namespace App\Console\Commands;

use App\Services\Firmware\ContractSchema;
use Illuminate\Console\Command;

class FirmwareContractCheck extends Command
{
    protected $signature = 'firmware:contract-check';

    protected $description = 'Validate offline firmware fixtures, JSON schemas and immutable contract checksums';

    public function handle(ContractSchema $schema): int
    {
        try {
            $root = base_path('contracts/firmware');
            $lock = json_decode(file_get_contents($root.'/contract-lock.json'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($lock['schemas'] as $name => $hash) {
                if (! hash_equals($hash, hash_file('sha256', $root.'/schemas/'.$name))) {
                    throw new \RuntimeException('Schema checksum drift: '.$name);
                }
            }
            foreach ($lock['fixtures'] as $fixture) {
                $file = $root.'/'.$fixture['file'];
                if (! hash_equals($fixture['sha256'], hash_file('sha256', $file))) {
                    throw new \RuntimeException('Fixture checksum drift: '.$fixture['file']);
                }
                $schema->validate($fixture['schema'], json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR));
            }
            $this->info(count($lock['fixtures']).' fixtures and '.count($lock['schemas']).' schemas verified; hardware approval=false.');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
