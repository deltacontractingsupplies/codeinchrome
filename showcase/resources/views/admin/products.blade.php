@extends('admin.layout')
@section('title', 'Products')
@section('content')
<h1>Products</h1>
<table class="lines">
    <thead><tr><th scope="col">Coffee</th><th scope="col">Roast</th><th scope="col" class="num">Price</th><th scope="col" class="num">Stock</th><th scope="col"><span style="position: absolute; left: -9999px">Edit</span></th></tr></thead>
    <tbody>
    @foreach ($products as $p)
        <tr>
            <td>{{ $p->name }} <span class="meta">- {{ $p->origin }}</span></td>
            <td>{{ ucfirst($p->roast) }}</td>
            <td class="num">{{ $p->price() }}</td>
            <td class="num">{{ $p->stock }}</td>
            <td><a href="{{ route('admin.products.edit', $p) }}">Edit</a></td>
        </tr>
    @endforeach
    </tbody>
</table>
@endsection
