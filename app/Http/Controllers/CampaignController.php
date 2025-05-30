<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\CampaignService;

class CampaignController extends Controller
{
    protected $campaign;
    public function __construct(CampaignService $campaign)
    {
        $this->middleware('isUser');
        $this->campaign = $campaign;
    }


    public function getCategories()
    {
        return $this->campaign->getCategories();
    }


    public function getCampaigns(Request $request)
    {
        return $this->campaign->getCampaigns($request);
    }

    public function addWorkerToCampaign(Request $request)
    {
        return $this->campaign->updateCampaignWorker($request);
    }

    public function postCampaign(Request $request)
    {
        return $this->campaign->create($request);
    }

    public function viewCampaign($job_id)
    {
        return $this->campaign->viewCampaign($job_id);
    }


    public function campaignActivitiesStat($campaignId)
    {
        return $this->campaign->campaignActivitiesStat($campaignId);
    }

    public function campaignActivitiesJob(Request $request, $campaignId)
    {
        return $this->campaign->campaignJobList($request, $campaignId);
    }

    public function allCampaignJob(Request $request)
    {
        return $this->campaign->allCampaignJobList($request);
    }

    public function pauseCampaign($campaignId)
    {
        return $this->campaign->pauseCampaign($campaignId);
    }

    public function jobDetails(Request $request)
    {
        return $this->campaign->jobDetails($request);
    }

    public function approveOrDeclineJob(Request $request)
    {
        return $this->campaign->approveOrDeclineJob($request);
    }


}
