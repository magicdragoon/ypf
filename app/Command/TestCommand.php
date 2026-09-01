<?php
namespace App\Command;

use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use OpenSpout\Writer\Common\Creator\Style\StyleBuilder;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use Ypf\Utils\ScryptUtil;
use YpfCommand;

class TestCommand extends YpfCommand
{
    public function handle()
    {
        $this->testOpenSpoutReader();
    }

    private function testHello()
    {
        echo 'hello world';
    }

    private function testScrypt()
    {
        $hash = '$scrypt$n=16384,r=8,p=1$qQLvxWvSYgQ0fN3yZ//QnQ$Xim3avPo8npUfmfUO+GRnbGqoidaswuGnZiM1ddJXRIhzhpX1ofLTnqVFKTKN2wuZ17HFZeLKSfl+qiWsSe2qQ';
        $password = '123456';
        var_dump(ScryptUtil::verify($hash, $password));
    }
    
    private function testOpenSpoutWriter()
    {
        $style = (new StyleBuilder())
            ->setFontBold()
            ->setFontColor(Color::RED)
            ->setBackgroundColor(Color::YELLOW)
            ->setFontSize(12)
            ->build();

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile(storage_path('test.xlsx'));
        $headerRow = WriterEntityFactory::createRowFromArray(['ID', '姓名', '邮箱', '注册时间'], $style);
        $writer->addRow($headerRow);
        for ($i = 1; $i <= 100000; $i++) {
            $rowData = [$i, "用户_$i", "user_$i@example.com", date('Y-m-d H:i:s')];
            $row = WriterEntityFactory::createRowFromArray($rowData);
            $writer->addRow($row);
        }
        $writer->close();
    }
    
    private function testOpenSpoutReader()
    {
        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->open(storage_path('test.xlsx'));
        foreach ($reader->getSheetIterator() as $sheet) {
            echo $sheet->getName() . PHP_EOL;
            foreach ($sheet->getRowIterator() as $row) {
                foreach ($row->getCells() as $cell) {
                    echo $cell->getValue() . ', ';
                }
                echo PHP_EOL;
            }
        }
        $reader->close();
    }
}