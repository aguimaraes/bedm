<?php

namespace App\Console\Commands;

use App\Protocol;
use Illuminate\Console\Command;
use NFePHP\MDFe\Tools;

class MDFeFinish extends Command
{

    /**
     * @var Tools
     */
    protected $tool;

    /**
     * @var int
     */
    protected $environment = 2;

    /**
     * @var string
     */
    protected $key;

    /**
     * @var string
     */
    protected $cnpj;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mdfe:finish {key} {environment=2} {protocol?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        $this->environment = $this->argument('environment');

        $this->key = $this->argument('key');

        $protocol = $this->argument('protocol');

        $this->cnpj = substr($this->key, 6, 14);

        $tool = $this->getTool();

        if (empty($protocol)) {
            $protocol = $this->getSuccessfulProtocol();
        }

        $result = [];

        $tool->sefazEncerra($this->key, $this->environment, 1, $protocol, '35', '3536505', $result);

        if ($result['cStat'] == "135") {
            $this->moveSuccessfulProtocol();
            $this->writeResult('OK', 'info', 'OK');
        }

        $this->writeResult($result['xMotivo']);

    }

    private function moveSuccessfulProtocol()
    {
        $namespace = $this->environment == 1 ? 'producao' : 'homologacao';
        $date = date('Ym');
        $protocolPath = storage_path("mdfe/{$namespace}/temporarias/{$date}/{$this->key}-EncMDFe-retEventoMDFe.xml");
        rename($protocolPath, $this->getOriginalFile() . '.finishProtocol.xml');
    }

    private function writeResult($msg, $type = 'error', $log = null)
    {
        if ($log) {
            $fileName = $this->getOriginalFile() . '.finish.txt';
            File::put($fileName, $log);
        }
        $this->{$type}($msg);
        die();
    }

    private function getOriginalFile()
    {
        $inbox = env('MDFE_INBOX', storage_path('mdfe/inbox'));
        return "{$inbox}/{$this->key}-mdfe";
    }

    /**
     * Configura e retorna a classe Tools
     *
     * @return Tools
     */
    private function getTool()
    {
        define('NFEPHP_ROOT', storage_path('mdfe/'));

        $config = json_decode(file_get_contents(storage_path('mdfe.json')));

        $config->cnpj = $this->cnpj;

        $tool = new Tools(json_encode($config));

        $this->tool = $tool;

        $envName = $this->environment == '1' ? 'production' : 'testing';

        $year = date('Y');

        $this->basePath = storage_path("mdfe/{$envName}/{$year}");

        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0777, true);
        }

        return $tool;
    }

    private function getSuccessfulProtocol()
    {
        $protocol = Protocol::where(['mdfe' => $this->key, 'status_code' => '100'])->first();
        if (!$protocol) {
            $this->error('MDFe não emitido ou protocolo não encontrado.');
            die;
        }
        return $protocol->protocol;
    }
}
