@extends('admin.layout')
@section('title', 'Edit '.$product->name)
@section('content')
<h1>{{ $product->name }}</h1>
<form method="POST" action="{{ route('admin.products.update', $product) }}" class="stack">@csrf @method('PUT')
    <label for="name">Name</label>
    <input id="name" name="name" value="{{ old('name', $product->name) }}" required>
    <label for="price_cents">Price, in cents</label>
    <input id="price_cents" name="price_cents" type="number" value="{{ old('price_cents', $product->price_cents) }}" required>
    <label for="stock">Bags in stock</label>
    <input id="stock" name="stock" type="number" value="{{ old('stock', $product->stock) }}" required>
    <label for="notes">Tasting notes</label>
    <input id="notes" name="notes" value="{{ old('notes', $product->notes) }}" required>
    <label for="description">Description</label>
    <textarea id="description" name="description" rows="5" required>{{ old('description', $product->description) }}</textarea>
    @if ($errors->any())<p class="flash error">{{ $errors->first() }}</p>@endif
    <p><button class="btn" style="margin-top: 16px">Save changes</button></p>
</form>
@endsection
