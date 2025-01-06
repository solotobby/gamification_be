<?php

namespace App\Repositories;

use App\Models\CampaignWorker;

class JobRepositoryModel
{
    public function getJobByType($user, $type)
    {
       return CampaignWorker::where(
            'user_id',
            $user->id
        )->where(
            'status',
            $type
        )->orderBy(
            'created_at',
            'ASC'
        )->paginate(10);
    }

    public function getDisputedJobs($user)
    {
       return CampaignWorker::where(
            'user_id',
            $user->id
        )->where(
            'is_dispute',
            true
        )->orderBy(
            'created_at',
            'ASC'
        )->get();
    }

    public function getCampaignStats($camId)
    {
        $counts = [
            'Pending' => 0,
            'Denied' => 0,
            'Approved' => 0,
        ];
        $statusCounts = CampaignWorker::where('campaign_id', $camId)
            ->whereIn('status', ['Pending', 'Denied', 'Approved'])
            ->selectRaw('status, count(*) as count')
            ->groupBy('sta  tus')
            ->get();

        // Map the result into the counts array
        foreach ($statusCounts as $statusCount) {
            $counts[$statusCount->status] = $statusCount->count;
        }

        return $counts;
    }

    public function getCampaignSpentAmount($camId)
    {
        $amounts = CampaignWorker::where(
            'campaign_id',
            $camId
        )->where(
            'status',
            'Approved'
        )->sum('amount');
        return $amounts;
    }

    public function getJobsByIdAndType($camId, $type)
    {
        $query = CampaignWorker::where(
            'campaign_id',
            $camId
        );
        if (!empty($type)) {
            $query->where(
                'status',
                $type
            );
        }
        return $query->orderBy(
            'created_at',
            'DESC'
        )->paginate(10);
    }

    public function getJobByIdAndCampaignId($jobId, $campaignId)
    {
        return CampaignWorker::where(
            'id',
            $jobId
        )->where(
            'campaign_id',
            $campaignId
        )->first();
    }

    public function getJobById($jobId)
    {
        return CampaignWorker::where(
            'id',
            $jobId
        )->first();
    }

    public function updateJobStatus($reason, $jobId, $status){

        $updateStatus = CampaignWorker::where(
            'id',
            $jobId
        )->first();

        $updateStatus->reason = $reason;
        $updateStatus->status = $status;
        $updateStatus->save();

        return $updateStatus;
    }
}
