@props(['code'])
{{-- TB 72: der Code in Ziffernfeldern; darunter klein die Ziffernreihe — ein Doppelklick markiert sie ganz zum Kopieren --}}
<table class="code" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="code-boxes" align="center" cellpadding="0" cellspacing="6" role="presentation" aria-hidden="true">
<tr>
@foreach (str_split($code) as $digit)
<td class="code-box" align="center">{{ $digit }}</td>
@endforeach
</tr>
</table>
<p class="code-plain">{{ $code }}</p>
</td>
</tr>
</table>
