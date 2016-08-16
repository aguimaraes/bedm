<?php

namespace App\Console\Commands;

use App\Lot;
use App\Protocol;
use App\Receipt;
use DB;
use File;
use Illuminate\Console\Command;
use Log;
use NFePHP\Common\Exception\InvalidArgumentException;
use NFePHP\MDFe\Tools;

class MDFe extends Command
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
     * @var Lot
     */
    protected $lotModel;

    /**
     * @var string
     */
    protected $originalFilePath;

    /**
     * @var string
     */
    protected $signedFilePath;

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
    protected $signature = 'mdfe:send {key} {environment=2}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envia um MDFe.';

    public function handle()
    {
        $this->environment = $this->argument('environment');

        $this->key = $this->argument('key');

        $this->cnpj = substr($this->key, 6, 14);

        try {

            $tool = $this->getTool();

            if ($receipt = $this->getWaitingReceipt()) {
                $this->getReceipt($receipt->receipt);
                die;
            }

            // 35160751013233000402580010000000391000949083
            $originalFile = $this->getOriginalFile();

            $signedFile = $this->signOriginalFile($originalFile);

            $this->persistSignedFile($signedFile);

            $lot = $this->sendLot($signedFile);

            $this->getReceipt($lot->receipt);

        } catch (InvalidArgumentException $e) {

            $this->writeResult($e->getMessage(), 'error', $e->getMessage());

        }
    }

    /**
     * Retorna o arquivo original e salva o caminho do arquivo
     *
     * @return string
     */
    private function getOriginalFile()
    {
        $inbox = env('MDFE_INBOX', storage_path('mdfe/inbox'));
        $this->originalFilePath = "{$inbox}/{$this->key}-mdfe.xml";
        return File::get($this->originalFilePath);
    }

    /**
     * @return bool
     */
    private function deleteOriginalFile()
    {
        return File::delete($this->originalFilePath);
    }

    /**
     * @param string $file
     *
     * @return string
     */
    private function signOriginalFile($file)
    {
        try {

            return $this->tool->assina($file);

        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->error("Não consegui assinar esse documento.");
            die;
        }
    }

    /**
     * @param string $file
     *
     * @return int
     */
    private function persistSignedFile($file)
    {
        $this->signedFilePath = "{$this->basePath}/{$this->key}-mdfe-signed.xml";
        return (boolean) File::put($this->signedFilePath, $file);
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

    /**
     * Retorna um recibo caso ele seja o último e esteja com o código 105.
     *
     * @return false|Receipt
     */
    private function getWaitingReceipt()
    {
        $receipt = Receipt::latest()->first();

        if (empty($receipt) || $receipt->status_code != '105') {
            return false;
        }

        return $receipt;
    }

    /**
     * @param $signedXML
     *
     * @return Lot
     */
    private function sendLot($signedXML)
    {
        $lot = $this->startLotPersistence();

        $lotResponse = $this->tool->sefazEnviaLote($signedXML, $this->environment, $lot->id);

        $data = $this->parseLotResponse($lotResponse);

        $lot = $this->commitLotPersistence($lot, $data);

        return $lot;
    }

    /**
     * @return Lot
     */
    private function startLotPersistence()
    {
        DB::beginTransaction();
        $lot = Lot::create([
            'environment' => $this->environment,
            'mdfe' => $this->key,
        ]);
        $this->lotModel = $lot;
        return $lot;
    }

    /**
     * @param string $response
     *
     * @return array
     */
    private function parseLotResponse($response)
    {
        $data = [];

        $MDFe = new \DOMDocument();

        $MDFe->loadXML($response);

        $receiptNumber = $MDFe->getElementsByTagName('nRec');
        if (! $receiptNumber->length) {
            throw \Exception('Não encontrei o número do recibo. Documento não enviado.');
        }
        $data['receipt'] = $receiptNumber->item(0)->nodeValue;

        $code = $MDFe->getElementsByTagName('cStat');
        if (!$code->length) {
            throw \Exception("Não encontrei o código do status desse recibo.");
        }
        $data['status_code'] = $code->item(0)->nodeValue;

        $reason = $MDFe->getElementsByTagName('xMotivo');
        if (!$reason->length) {
            throw \Exception("Não encontrei o código do status desse recibo.");
        }
        $data['status_msg'] = $reason->item(0)->nodeValue;

        return $data;
    }

    /**
     * @param Lot $lot
     * @param array $data
     *
     * @return Lot
     */
    private function commitLotPersistence(Lot $lot, array $data)
    {
        $this->info('Lote enviado.');
        $lot->update($data);
        DB::commit();
        return $lot;
    }

    /**
     * @param $number
     *
     * @return array
     */
    private function getReceipt($number)
    {
        $this->tool->sefazConsultaRecibo($number, (string) $this->environment, $receipt);
        $this->parseReceiptResponse($receipt);
        return $receipt;
    }

    private function parseReceiptResponse(array $result)
    {
        $receipt = Receipt::create([
            'environment' => $this->environment,
            'status_code' => $result['cStat'],
            'status_msg' => $result['xMotivo'],
            'receipt' => $result['nRec'],
            'mdfe' => $this->key,
        ]);

        if ($result['cStat'] == "104") { // processado?
            return $this->parseProtocol($result);
        } elseif ($result['cStat'] == "105") {
            return $this->lotIsWaiting($result);
        }

        return $this->processNegativeLotResult($result);
    }

    private function processNegativeLotResult($result)
    {
        $this->error("Lote não enviado.");
    }

    private function parseProtocol(array $result)
    {
        if (! $result['aProt']) {
            $this->writeResult('Resposta desse recibo estava sem protocolo', 'error', 'Recibo sem protocolo');
        }
        $protocol = $result['aProt'];
        $protocolModel = Protocol::create([
            'environment' => $this->environment,
            'protocol' => $protocol['nProt'],
            'digval' => $protocol['digVal'],
            'status_code' => $protocol['cStat'],
            'status_msg' => $protocol['xMotivo'],
            'mdfe' => $this->key,
            'receipt' => $result['nRec'],
        ]);
        if ($protocol['cStat'] != "100") {
            return $this->documentUnauthorized($protocol, $protocolModel);
        }
        return $this->documentAuthorized($protocol, $protocolModel);
    }

    private function lotIsWaiting($result)
    {
        $this->warn("Documento ainda não foi processado. Tente outra vez.");
        $this->writeResult(
            'Documento ainda não foi processado. Tente outra vez.',
            'warn',
            'Documento não processado. Tente outra vez.'
        );
    }

    private function writeResult($msg, $type = 'error', $log = null)
    {
        if ($log) {
            $fileName = $this->originalFilePath . '.output';
            File::append($fileName, $log);
        }
        $this->{$type}($msg);
        die();
    }

    private function documentAuthorized($protocol, Protocol $protocolModel)
    {
        $this->info('Documento autorizado.');
        $this->writeProtocol();
    }

    private function writeProtocol()
    {
        $namespace = $this->environment == 1 ? 'producao' : 'homologacao';
        $date = date('Ym');
        $receipt = $this->lotModel->receipt;
        $protocolPath = storage_path("mdfe/{$namespace}/temporarias/{$date}/{$receipt}-retConsReciMDFe.xml");
        $withProtocol = $this->tool->addProtocolo($this->signedFilePath, $protocolPath);
        if (!file_put_contents("{$this->basePath}/{$this->key}-protMDFe.xml", $withProtocol)) {
            throw new \Exception('Não consegui salvar o MDFe com protocolo.');
        }
        $this->writeResult('OK', 'info', 'OK');
        $this->deleteOriginalFile();
    }

    private function documentUnauthorized($protocol, Protocol $protocolModel)
    {
        if ($protocol['cStat'] == "204") {
            // duplicidade, vai buscar o recibo correto
            $r = $protocol['xMotivo'];
            $receiptNumber = str_replace('nRec:', '', substr($r, strpos($r, 'nRec:'), -1));
            // pega o recibo correto
            $this->getReceipt($receiptNumber);
        }
    }
}
