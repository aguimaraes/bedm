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

        try {

            $config = json_decode(file_get_contents(storage_path('mdfe.json')));

            $config->cnpj = '51013233000402';

            //35160751013233000402580010000000391000949083
            $mdfe = file_get_contents(storage_path("mdfe/{$this->key}-mdfe.xml"));

            $tool = new Tools(json_encode($config));

            $this->tool = $tool;

            // assina o xml
            $signed = $tool->assina($mdfe);

            $sendXML = $this->sendLot($signed);

            $recipeNumber = $this->getRecipeNumber($sendXML);

            $recipe = $this->getRecipe($recipeNumber);

        } catch (InvalidArgumentException $e) {

            $this->error($e->getMessage());

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
