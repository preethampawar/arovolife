{{-- Reopens the Google Analytics cookie banner (partials._google-analytics),
     so withdrawing consent is as easy as giving it (DPDP Act §6(4)). Rendered
     hidden; the GA partial's script unhides it, so on a page without GA
     (password reset, grievance pages, signed links, no GA id) neither it nor
     its separator shows. --}}
<span data-cookie-settings hidden class="no-print print:hidden">&middot; <button type="button" class="underline hover:text-gray-900">Cookie settings</button></span>
