<?php

namespace App\Repositories;

use App\Models\Campaign;
use App\Models\CampaignWorker;
use App\Models\DisputedJobs;

class JobRepositoryModel
{
    public function getJobByType($user, $type, $page = null)
    {
        $query = CampaignWorker::where(
            'user_id',
            $user->id
        );

        if ($type === 'approved') {
            $query->whereIn(
                'status',
                ['approved', 'denied']
            );
        } else {
            $query->where(
                'status',
                $type
            );
        }

        return $query->orderBy(
            'created_at',
            'ASC'
        )->paginate(
            10,
            ['*'],
            'page',
            $page
        );
    }

    public function getJobsByCampaignIdsAndType($campaignIds, $type, $page = null)
    {
        return CampaignWorker::whereIn(
            'campaign_id',
            $campaignIds
        )
            ->where(
                'status',
                $type
            )
            ->orderBy(
                'created_at',
                'ASC'
            )
            ->paginate(
                10,
                ['*'],
                'page',
                $page
            );
    }


    public function createJobs($user, $campaignId, $request, $currency, $proofUrl, $unitPrice)
    {
        $campaignWorker = CampaignWorker::create([
            'user_id' => $user->id,
            'campaign_id' => $campaignId,
            'comment' => $request->comment,
            'amount' => $unitPrice,
            'proof_url' => $proofUrl,
            'currency' => $currency->code,
        ]);
        return $campaignWorker;
    }

    public function setPendingCount($id)
    {
        $campaign = Campaign::where('id', $id)->first();
        $campaign->number_of_staff;
        if ($campaign->pending_count == $campaign->number_of_staff) {
            $campaign->is_completed = true;
            $campaign->save();
            return 'OK';
        } else {
            return 'NOT OK';
        }
    }
    public function getDisputedJobs($user, $page = null)
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
        )->paginate(
            10,
            ['*'],
            'page',
            $page
        );
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
            ->groupBy('status')
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

    public function getJobsByIdAndType($camId, $type, $page = null)
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
        )->paginate(10, ['*'], 'page', $page);
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

    public function availableJobs($userId, $category = null, $page = null)
    {
        $completedCampaignIds = CampaignWorker::where(
            'user_id',
            $userId
        )
            ->pluck('campaign_id')
            ->toArray();
        return Campaign::where(
            'status',
            'Live'
        )->where(
            'is_completed',
            false
        )->where(
            'user_id',
            '!=',
            $userId
        )->whereNotIn(
            'id',
            $completedCampaignIds
        )->when($category, function ($query) use ($category) {
            $query->where(
                'campaign_type',
                $category
            );
        })->orderByRaw(
            "CASE WHEN approved = 'prioritize' THEN 1 ELSE 2 END"
        )
            ->orderBy(
                'created_at',
                'DESC'
            )->paginate(
                10,
                ['*'],
                'page',
                $page
            );
    }

    public function getJobById($jobId)
    {
        return Campaign::where(
            'job_id',
            $jobId
        )->first();
    }
    public function     getMyJobById($jobId, $userId)
    {
        $query = CampaignWorker::where(
            'id',
            $jobId
        );
        if ($userId) {
            $query->where(
                'user_id',
                $userId
            );
        }
        return $query->first();
    }


    public function updateJobStatus($reason, $jobId, $status)
    {

        $updateStatus = CampaignWorker::where(
            'id',
            $jobId
        )->first();

        $updateStatus->reason = $reason;
        $updateStatus->status = $status;
        $updateStatus->save();

        return $updateStatus;
    }

    public function checkIfJobIsDoneByUser($id)
    {
        return CampaignWorker::where(
            'user_id',
            auth()->id()
        )->where(
            'campaign_id',
            $id
        )->exists();
    }
    public function createDisputeOnWorker($jobId)
    {
        $updateStatus = CampaignWorker::where(
            'id',
            $jobId
        )->first();

        $updateStatus->is_dispute = true;
        $updateStatus->save();

        return $updateStatus;
    }

    public function createDispute($job, $reason, $proof)
    {
        $dispute = DisputedJobs::create([
            'campaign_worker_id' => $job->id,
            'campaign_id' => $job->campaign_id,
            'user_id' =>    $job->user_id,
            'reason' => $reason,
            'url' => $proof,
        ]);

        return $dispute;
    }
}
