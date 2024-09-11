<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CategoryList;

class getCategory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:get-category';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = date("Y-m-d H:i:s");

        $result = $this->get_curl();
        $decode_res = json_decode($result, true);
        $categoryPoint = $decode_res['test'];
        $query = [];
        foreach($categoryPoint as $key => $val)
        {
            foreach($val['test2'] as $key2 => $val2)
            {
                $cat_id     = $val2['id'];
                $cat_name   = $val2['name'];

                $getCategoryList = CategoryList::where('cat_id', $cat_id)->where('shop_type', 1)->first();
                if($getCategoryList)
                    continue;

                array_push($query, [
                    'cat_id'        => $cat_id,
                    'cat_name'      => $cat_name,
                    'shop_type'     => 1,
                    'create_date'   => $date
                ]);
            }
        }

        CategoryList::insert($query);
    }

    protected function get_curl()
    {
        // cURL 세션 초기화
        $curl = curl_init();

        // 요청에 필요한 URL
        $url = "";

        // 요청에 필요한 헤더 설정
        $headers = [
            
        ];

        // cURL 옵션 설정
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "", // 자동으로 압축 해제
            CURLOPT_HTTPHEADER => $headers,
//        CURLOPT_COOKIE => $cookie,
        ]);

        // 요청 실행 및 응답 저장
        $response = curl_exec($curl);

        // cURL 오류 확인
        if (curl_errno($curl)) {
            echo 'cURL Error: ' . curl_error($curl);
        }

        // cURL 세션 종료
        curl_close($curl);

        return $response;
    }
}
