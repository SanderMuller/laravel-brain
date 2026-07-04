@each('partials.header', $rows, 'row')
@includeWhen($cond, 'components.button')
@includeFirst(['components.logo', 'missing.one'])
@include('components.card')
<x-card/>
