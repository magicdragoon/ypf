<?php
namespace App\Command;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\Common\Creator\ReaderFactory;
use OpenSpout\Writer\Common\Creator\WriterFactory;
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
        $style = (new Style())
            ->setFontBold()
            ->setFontColor(Color::RED)
            ->setBackgroundColor(Color::YELLOW)
            ->setFontSize(12);

        $file = storage_path('test.xlsx');
        $writer = WriterFactory::createFromFile($file);
        $writer->openToFile($file);
        $headerRow = Row::fromValues(['ID', '姓名', '邮箱', '注册时间'], $style);
        $writer->addRow($headerRow);
        for ($i = 1; $i <= 100; $i++) {
            $rowData = [$i, "用户_$i", "user_$i@example.com", date('Y-m-d H:i:s')];
            $row = Row::fromValues($rowData);
            $writer->addRow($row);
        }
        $writer->close();
    }

    private function testOpenSpoutReader()
    {
        $file = storage_path('test.xlsx');
        $reader = ReaderFactory::createFromFile($file);
        $reader->open($file);
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
