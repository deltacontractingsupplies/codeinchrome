@extends('admin.layout')
@section('title', 'Orders')
@section('content')
<h1>Orders</h1>
@include('admin._orders', ['orders' => $orders])
{{ $orders->links() }}
@endsection
