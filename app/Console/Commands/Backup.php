<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

class Backup extends Command
{
    private string $path_db = 'backup/db/';
    private string $path_www = 'backup/www/'; // path menyimpan aplikasi storage/..
    private string $path_apps = '/Users/edp/Documents/doc'; // path aplikasi yang ingin di backup
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:all';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'membackup semua data dalam direktori dan databasenya';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        // mengecek apakah path untuk database tersedia
        if (!Storage::exists($this->path_db)) {
            Storage::makeDirectory($this->path_db);
        }
        //mengecek apakah path untuk aplikasi tersedia
        if (!Storage::exists($this->path_www)) {
            Storage::makeDirectory($this->path_www);
        }
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // 'melakukan backup database'
        $this->_backupDatabase();
        // melakukan backup aplikasi
        $this->_backupDirectory();
        echo 'success backup all data' . PHP_EOL;
    }

    private function _backupDatabase()
    {
        //menambahkan tanggal pada path
        $dateNow = date('Y-m-d');
        $dateOneWeekAgo = date('Y-m-d', strtotime('-1 week'));
        $pathNow = $this->path_db . $dateNow . '/';
        $pathOneWeekAgo = $this->path_db . $dateOneWeekAgo . '/';
        // membuat path jika path belum tersedia
        if (!Storage::exists($pathNow)) {
            Storage::makeDirectory($pathNow);
        }

        $ignoreDatabase = ['information_schema', 'localDatatabase', 'performance_schema', 'sys'];
        $databases = DB::select('SHOW DATABASES');
        // melakukan backup database
        foreach ($databases as $database) {
            $dbName = $database->Database;
            if (!in_array($dbName, $ignoreDatabase)) {
                $backupFileName = $dbName . '_' . $dateNow . '.sql';
                // Perintah untuk melakukan backup database menggunakan mysqldump
                $backupCommand = sprintf(
                    'mysqldump -u %s %s > %s',
                    'root',
                    $dbName,
                    Storage::path($pathNow) . $backupFileName
                );
                // Menjalankan perintah backup menggunakan exec()
                exec($backupCommand);

                // Menghapus file backup yang tersimpan di direktori lokal 1 minggu yang lalu
                if (Storage::exists($pathOneWeekAgo)) {
                    Storage::deleteDirectory($pathOneWeekAgo);
                }

                Log::info('sukses backup ' . $dbName);
                echo 'sukses backup ' . $dbName . PHP_EOL;
            }
        }
        // melakukan upload kedalam cloud 
        // mengcopy kedalam nas
    }

    private function _backupDirectory()
    {

        $zip = new ZipArchive();
        $fileName = 'backup.zip';
        $sourceFolder = $this->path_apps;

        $output = new ConsoleOutput();
        $progressBar = new ProgressBar($output);

        try {
            if ($zip->open(Storage::path($this->path_www . $fileName), ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($sourceFolder),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );

                $totalFiles = iterator_count($files);
                $progressBar->setMaxSteps($totalFiles);
                $progressBar->start();

                foreach ($files as $name => $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($sourceFolder) + 1);
                        $zip->addFile($filePath, $relativePath);
                    }
                    $progressBar->advance();
                }

                $zip->close();
                $progressBar->finish();
                $output->writeln('');
                Log::info('Berhasil melakukan backup file');
            } else {
                Log::info('Gagal membuat file zip');
            }
        } catch (\Throwable $th) {
            Log::info('Gagal melakukan backup file');
        }
    }
}
