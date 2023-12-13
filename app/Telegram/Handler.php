<?php

namespace App\Telegram;

use DefStudio\Telegraph\Facades\Telegraph;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Keyboard\ReplyButton;
use DefStudio\Telegraph\Keyboard\ReplyKeyboard;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Stringable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class Handler extends WebhookHandler{

    public function start(){
        $chatId = $this->chat->info()['id'];

        if(!empty($this->message)){
            $userName = $this->message->from()->firstName();
        }else{
            $userName =  $this->callbackQuery->toArray()['from']['username'];
        }
        if(!empty($this->message)){
            $mssgId = $this->message->id();
        }else{
            $mssgId =  $this->callbackQuery->toArray()['message']['id'];
        }
        $helloTxt  = "Привет, *$userName*" . PHP_EOL;
        $helloTxt .= '*N.Budget* — это бот для того, чтобы ты мог вести свой бюджет.' . PHP_EOL;
        $helloTxt .= 'Выбери действие, для того, чтобы начать пользоваться ботом: ' . PHP_EOL;
        Telegraph::chat($chatId)->deleteMessage($mssgId)->send();

        Telegraph::chat($chatId)->message($helloTxt)
            ->photo(Storage::path('../../storage/app/public/logo.jpg'))
            ->keyboard(Keyboard::make()
                ->buttons([
                    Button::make('Просмотреть информацию текущего периода')
                        ->action('info')
                        ->param('mssgId', "$mssgId"),
                    Button::make('Инструкция как пользоваться ботом')
                        ->url('https://telegra.ph/Instrukciya-kak-polzovatsya-botom-NBudget-12-09')
                ])
            )->send();
    }

    public function info(){
        $chatId = $this->chat->info()['id'];

        $userId = $this->callbackQuery->toArray()['from']['id'];
        define('NO_INFO', 'За текущий период информация не найдена');
        $arrMonths = array(
            'Январь',
            'Февраль',
            'Март',
            'Апрель',
            'Май',
            'Июнь',
            'Июль',
            'Август',
            'Сентябрь',
            'Октябрь',
            'Ноябрь',
            'Декабрь'
        );
        $month = date('m')-1;
        $monthYear =  date('m.Y');
        $str = '*' . $arrMonths[$month] . ' ' . date('Y'). '*' . PHP_EOL;
        $dataIncome = DB::select("select * from income where user_id = '$userId' and date = '$monthYear'");
        $dataRequiredCosts = DB::select("select * from costs where user_id = '$userId' and date = '$monthYear' and type='r'");
        
        if(empty($dataIncome) && empty($dataCosts)){
            $str .= NO_INFO;
        }else{
            $sumIncome = 0;
            $sumReqCosts = 0;
            // income
            $str .= '📈*Доход:*' . PHP_EOL;

            foreach($dataIncome as $data){
                $str .= "       {$data->name} : {$data->sum} ₽" . PHP_EOL;
                $sumIncome += $data->sum;
            }

            $str .= '       _Итого:_ ' . $sumIncome . '₽' . PHP_EOL;

            //required costs
            $str .= '📉*Обязательные расходы:*' . PHP_EOL;
            
            foreach($dataRequiredCosts as $data){
                $str .= "       {$data->name} : {$data->sum} ₽" . PHP_EOL;
                $sumReqCosts += $data->sum;
            }
            
            //текущая дата в Unix формате
            $lastDayMonth = date('t');
            $todayMonth = date('d');
            $freeMoney = round($sumIncome - $sumReqCosts, 2);
            $inDay = round($freeMoney / ($lastDayMonth - $todayMonth), 2);
            $str .= '       _Итого:_ ' . $sumReqCosts . '₽' . PHP_EOL;
            $str .= '🆓*Свободных денег :* ' . $freeMoney . '₽' . PHP_EOL;
            $str .= '      📆*В день:* ' . $inDay . '₽';
        }
        
        Telegraph::chat($chatId)->deleteMessage($this->data->get('mssgId')+1)->send();

        Telegraph::chat($chatId)->message($str)
            ->keyboard(Keyboard::make()
                ->row([
                    Button::make('Добавить доход')
                        ->action('income'),
                    Button::make('Добавить обязательные расходы')
                        ->action('requiredCosts'),
                ])
                ->row([
                    Button::make('Добавить ежедевные расходы')
                        ->action('dailyCosts'),
                ])
                ->row([
                    Button::make('Просмотреть информацию за другой период')
                        ->action('showInfoOtherPeriod'),
                ])
                ->row([
                    Button::make('<< Вернуться в меню')
                        ->action('start'),
                ]))
            ->send();
    }       

    protected function handleChatMessage(Stringable $text): void {
        $chatId = $this->chat->info()['id'];
        $userId = $this->message->from()->id();
        $actionType = DB::select('select value from cache where user_id = ?', [$userId]);
        $date =  date('m.Y');

        if(!empty($actionType) && $actionType[0]->value == "income"){
            $mssgId = json_encode($this->message->id());

            $incomingData = explode(" ", $text); 
            $name = $incomingData[0];
            $sum = $incomingData[1];
            DB::insert('insert into income (user_id, sum , name, date) values (?, ?, ?, ?)', [$userId, $sum, $name, $date]);
            Telegraph::chat($chatId)->deleteMessage((int)$mssgId)->send();
            Telegraph::chat($chatId)->deleteMessage((int)$mssgId-1)->send();
            Telegraph::chat($chatId)->edit((int)$mssgId-2)
                ->message('Обновите данное сообщение')
                ->keyboard(Keyboard::make()
                    ->buttons([
                        Button::make('Обновить данные')
                        ->action('info'),
                    ])
                )
                ->send();
            DB::update("update cache set value = ' ' WHERE user_id = $userId");
        }else if(!empty($actionType) && $actionType[0]->value == "requiredCosts"){
            $mssgId = json_encode($this->message->id());

            $incomingData = explode(" ", $text); 
            $name = $incomingData[0];
            $sum = $incomingData[1];
            DB::insert('insert into costs (user_id, sum , name, date, type) values (?, ?, ?, ?, ?)', [$userId, $sum, $name, $date, 'r']);
            Telegraph::chat($chatId)->deleteMessage((int)$mssgId)->send();
            Telegraph::chat($chatId)->deleteMessage((int)$mssgId-1)->send();
            Telegraph::chat($chatId)->edit((int)$mssgId-2)
                ->message('Обновите данное сообщение')
                ->keyboard(Keyboard::make()
                    ->buttons([
                        Button::make('Обновить данные')
                        ->action('info'),
                    ])
                )
                ->send();
            DB::update("update cache set value = ' ' WHERE user_id = $userId");
        }
    }

    public function income(){
        $chatId = $this->chat->info()['id'];
        $dataCallbackQuery = $this->callbackQuery->toArray();
        // Получаем данные от ТГ callback_query->user_id & data 
        $userId = print_r($dataCallbackQuery['from']['id'], true);
        $value = print_r($dataCallbackQuery['data']['action'], true);
        $isUserIdDB = DB::select("select user_id from cache where user_id = '$userId'"); //Ищет userID в БД. 

        if(empty($isUserIdDB)){
            DB::insert("insert into cache (user_id, value) values (?, ?)", [$userId, $value]);
        }else{
            DB::update("update cache set value = '$value' WHERE user_id = '$userId'"); 
        }
        $str  = 'Введите наименование и сумму дохода.' . PHP_EOL;
        $str .= '*Внимание! Наименование и сумма указывается через пробел!* Например: _"Зарплата 50000"_';

        Telegraph::chat($chatId)->message($str)->send();
    }

    public function requiredCosts(){
        $chatId = $this->chat->info()['id'];
        $dataCallbackQuery = $this->callbackQuery->toArray();
        // Получаем данные от ТГ callback_query->user_id & data 
        $userId = print_r($dataCallbackQuery['from']['id'], true);
        $value = print_r($dataCallbackQuery['data']['action'], true);
        $isUserIdDB = DB::select("select user_id from cache where user_id = '$userId'"); //Ищет userID в БД. 

        if(empty($isUserIdDB)){
            DB::insert("insert into cache (user_id, value) values (?, ?)", [$userId, $value]);
        }else{
            DB::update("update cache set value = '$value' WHERE user_id = '$userId'"); 
        }
        $str  = 'Введите наименование и сумму обязательных рассходов.' . PHP_EOL;
        $str .= '*Внимание! Наименование и сумма указывается через пробел!* Например: _"Кредит 20000"_';

        Telegraph::chat($chatId)->message($str)->send();
    }

    public function dailyCosts(){}
    
    public function showInfoOtherPeriod(){}
}

