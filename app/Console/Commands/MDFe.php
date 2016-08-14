<?php

namespace App\Console\Commands;

use App\Lot;
use App\Protocol;
use App\Receipt;
use NFePHP\MDFe\Tools;
use Illuminate\Console\Command;
use NFePHP\Common\Exception\InvalidArgumentException;

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
        define('NFEPHP_ROOT', storage_path('mdfe/'));

        $this->environment = $this->argument('environment');

        $this->key = $this->argument('key');

        $this->cnpj = substr($this->key, 6, 14);

        try {
            $config = json_decode(file_get_contents(storage_path('mdfe.json')));

            $config->cnpj = $this->cnpj;

            $baseDir = $this->environment == "1" ? 'production' : 'testing';

            $date = date('Ym');

            $path = "mdfe/{$baseDir}/{$this->cnpj}/{$date}";

            $directory = storage_path($path);

            $this->basePath = $directory;

            if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
                throw new \Exception("Não consegui criar o diretório.");
            }

            $this->originalFilePath = "{$directory}/{$this->key}-mdfe.xml";

            if (!is_readable($this->originalFilePath)) {
                throw new \Exception("Não consegui encontrar o arquivo original.");
            }

            // 35160751013233000402580010000000391000949083
            $mdfe = file_get_contents($this->originalFilePath);

            $tool = new Tools(json_encode($config));

            $this->tool = $tool;

            // assina o xml
            $signed = $tool->assina($mdfe);

            $this->signedFilePath = "{$directory}/{$this->key}-signed.xml";

            if (!file_put_contents($this->signedFilePath, $signed)) {
                throw new \Exception("Não consegui gravar o arquivo assinado.");
            }

            $sendXML = $this->sendLot($signed);

            $recipeNumber = $this->getRecipeNumber($sendXML);

            $recipe = $this->getRecipe($recipeNumber);

        } catch (InvalidArgumentException $e) {

            $this->error($e->getMessage());

            $file = "{$this->basePath}/$key.err";
            file_put_contents($file, $e->getMessage());

        }
    }

    /**
     * @param $signed
     *
     * @return \DOMDocument
     */
    private function sendLot($signed)
    {
        $lotModel = Lot::create([
            'environment' => $this->environment,
            'mdfe' => $this->key
        ]);

        $this->lotModel = $lotModel;

        $sendXMLString = $this->tool->sefazEnviaLote($signed, $this->environment, $lotModel->id);

        $sendXML = new \DOMDocument();

        $sendXML->loadXML($sendXMLString);

        return $sendXML;
    }

    /**
     * @param \DOMDocument $answer
     *
     * @return string
     */
    private function getRecipeNumber(\DOMDocument $answer)
    {
        $recipeNumber = $answer->getElementsByTagName('nRec');
        if (! $recipeNumber->length) {
            throw \Exception('Não encontrei o número do recibo. Documento não enviado.');
        }
        $receiptNumber = $recipeNumber->item(0)->nodeValue;

        $cStat = $answer->getElementsByTagName('cStat');
        if (!$cStat->length) {
            throw \Exception("Não encontrei o código do status desse recibo.");
        }
        $code = $cStat->item(0)->nodeValue;
        $this->lotModel->update([
            'receipt' => $receiptNumber,
            'status_code' => $code,
        ]);
        return $receiptNumber;
    }

    private function getRecipe($recipeNumber)
    {
        $answer = $this->tool->sefazConsultaRecibo($recipeNumber, (string) $this->environment, $result);
        \Log::debug("Raw Lot response: {$answer}");
        if ($result['bStat']) {
            return $this->processPositiveLotResult($result);
        }
        return $this->processNegativeLotResult($result);
    }

    private function processPositiveLotResult($result)
    {
        $this->info("Lote enviado.");
        $receiptModel = Receipt::create([
            'environment' => $this->environment,
            'status_code' => $result['cStat'],
            'receipt' => $result['nRec'],
            'mdfe' => $this->key,
        ]);
        if ($result['cStat'] == "104") {
            // arquivo processado
            return $this->lotWasProcessed($result);
        } elseif ($result['cStat'] === "105") {
            return $this->lotIsWaiting($result);
        }

        return $this->processNegativeLotResult($result);
    }

    private function processNegativeLotResult($result)
    {
        $this->error("Lote não enviado.");
    }

    private function lotWasProcessed(array $result)
    {
        if (! $result['aProt']) {
            $this->error("Resultado desse lote estava vazio.");
            return false;
        }
        $protocol = $result['aProt'];
        $reason = $protocol['xMotivo'];
        $protocolModel = Protocol::create([
            'environment' => $this->environment,
            'protocol' => $protocol['nProt'],
            'digval' => $protocol['digVal'],
            'status_code' => $protocol['cStat'],
            'reason' => $reason,
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
    }

    private function documentUnauthorized($protocol, Protocol $protocolModel)
    {
        if ($protocol['cStat'] == "204") {
            // duplicidade, vai buscar o recibo correto
            $r = $protocol['xMotivo'];
            $receiptNumber = str_replace('nRec:', '', substr($r, strpos($r, 'nRec:'), -1));
            // pega o recibo correto
            $this->getRecipe($receiptNumber);
        }
    }
}
