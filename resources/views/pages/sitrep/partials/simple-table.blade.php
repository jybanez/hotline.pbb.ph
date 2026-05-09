<div class="sitrep-table-card">
    <h3>{{ $title }}</h3>
    @if (count($rows) > 0)
        <table class="sitrep-table">
            <thead>
                <tr>
                    @foreach ($headers as $header)
                        <th>{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="sitrep-empty">{{ $empty }}</p>
    @endif
</div>
