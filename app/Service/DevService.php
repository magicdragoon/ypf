<?php
namespace app\Service;

use App\Data\TestData;

class DevService
{
    public function test(TestData $data)
    {
        return $data->resp();
    }
}
