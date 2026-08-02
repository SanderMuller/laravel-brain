@extends('layouts.app')

@include('partials.header')

<x-card title="Hi"/>
<x-alert.error/>
<x-menu/>

{{-- these must NOT produce edges --}}
@include('missing.partial')
<x-livewire::modal/>
