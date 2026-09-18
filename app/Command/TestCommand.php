<?php
namespace App\Command;

use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use OpenSpout\Writer\Common\Creator\Style\StyleBuilder;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use Command;

class TestCommand extends Command
{
    public function handle()
    {
        $this->testHello();
        // $this->testOpenSpoutWriter();
        // $this->testOpenSpoutReader();
    }

    private function testHello()
    {
        echo 'hello world', PHP_EOL;
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
