<?php

namespace App\Console\Commands;

use NFePHP\MDFe\Tools;
use Illuminate\Console\Command;
use NFePHP\Common\Exception\InvalidArgumentException;

class MDFe extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mdfe:send';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envia um MDFe.';

    public function handle()
    {
        define('NFEPHP_ROOT', storage_path('mdfe/'));
        try {

            $config = json_decode(file_get_contents(storage_path('mdfe.json')));

            $config->cnpj = '07976556000167';

            $mdfe = file_get_contents(storage_path('mdfe/modelo-aprovado-teste.xml'));

            $tool = new Tools(json_encode($config));

            $this->tool = $tool;

            // assina o xml
            $signed = $tool->assina($mdfe);

            $sendXMLString = $tool->sefazEnviaLote($signed);

            $sendXML = new \DOMDocument();

            $sendXML->loadXML($sendXMLString);

            $recipeNumber = $this->getRecipeNumber($sendXML);

            $recipe = $this->getRecipe($recipeNumber);

        } catch (InvalidArgumentException $e) {

            $this->error($e->getMessage());

        }
    }
    private function getRecipeNumber(\DOMDocument $answer)
    {
        $recipeNumber = $answer->getElementsByTagName('nRec');
        if (! $recipeNumber->length) {
            throw \Exception('Não encontrei o número do recibo. Documento não enviado.');
        }
        return $recipeNumber->item(0)->nodeValue;
    }

    private function getRecipe($recipeNumber)
    {
        $answer = $this->tool->sefazConsultaRecibo($recipeNumber, '2', $result);
        dd($result);
        return $recipeNumber;
    }
}
