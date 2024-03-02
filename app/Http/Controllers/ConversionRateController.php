<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConversionRateRequest;
use App\Http\Requests\UpdateConversionRateRequest;
use App\Models\ConversionRate;

class ConversionRateController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $conversions = ConversionRate::all();
        return view('admin.conversion.index', ['rates' => $conversions]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreConversionRateRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreConversionRateRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\ConversionRate  $conversionRate
     * @return \Illuminate\Http\Response
     */
    public function show(ConversionRate $conversionRate)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\ConversionRate  $conversionRate
     * @return \Illuminate\Http\Response
     */
    public function edit(ConversionRate $conversionRate)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateConversionRateRequest  $request
     * @param  \App\Models\ConversionRate  $conversionRate
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateConversionRateRequest $request, ConversionRate $conversionRate)
    {
        $conversionRate = ConversionRate::find($request->id);
        $conversionRate->update(['amount' => $request->rate]);
        return back()->with('success', 'Rate updated!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\ConversionRate  $conversionRate
     * @return \Illuminate\Http\Response
     */
    public function destroy(ConversionRate $conversionRate)
    {
        //
    }
}
