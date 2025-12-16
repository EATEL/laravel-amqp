<?php

namespace App\Observers\Observers;

use App\Models\Task;
use Rev\Amqp\Amqp;
use Str;

class SalesforceTaskObserver
{
    static $statusMap = [
        'in_progress' => "In Progress",
        'committed' => "Completed",
        'locked' => "Completed",
    ];
    public function updated(Task $task) {
        if(
            $task->wasChanged('status') 
            && array_key_exists($task->status, self::$statusMap) 
            && $task->salesforceEntity 
            && in_array($task->salesforceEntity->type, ['WorkOrder', 'WorkOrderLineItem'])
        ) {
            $routingKey = "revtek." . Str::kebab($task->salesforceEntity->type) . ".status-changed";
            \Log::info('publishing message: ', [[
                'id' => $task->salesforce_id,
                'status' => self::$statusMap[$task->status],
            ], 'salesforce', $routingKey]);
            Amqp::publish([
                'id' => $task->salesforce_id,
                'status' => self::$statusMap[$task->status],
            ], 'salesforce', $routingKey);
        }
    }
}
