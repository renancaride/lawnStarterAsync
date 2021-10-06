<?php

namespace App\Console\Commands;

use App\FileImport;
use App\Jobs\DownloadFileAsync;
use App\Jobs\ParseImportedFile;
use App\SIA\Endpoints\AvailableFileList as AvailableFileListEndpoint;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;

class SIAImportUsers extends Command implements ShouldQueue
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sia:import-users {file?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import Banco do Brasil SIA Users';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(AvailableFileListEndpoint $availableFileList)
    {
        if ($this->argument('file')) {
            logger('Processing file id ' . $this->argument('file'));
            ParseImportedFile::dispatch(FileImport::find($this->argument('file')));

            return;
        }

        /** @var string */
        $fileType = 'ARHF0097';

        logger('Getting available files to download...');

        /** @var App\SIA\Endpoints\DTO\AvailableFileForDownload */
        $availableFile = $availableFileList
            ->responseAsCollection()
            ->theMostestRecentFiles()
            ->withNameContaining($fileType)
            ->first();

        logger('Found file: ', $availableFile->toArray());

        DownloadFileAsync::dispatch($availableFile);
    }
}
