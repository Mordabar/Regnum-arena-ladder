{{-- El boton de la zona, llamando la atencion.

     Cuando salta el cruce -y otra vez cuando se confirma y empieza el
     combate- lo unico que hay que hacer ya mismo es mirar DONDE se queda. El
     nombre de la zona estaba ahi, en dorado y pulsable, pero no se leia como
     un boton: la gente lo miraba y seguia buscando el mapa en otra parte.

     Late hasta que se abre el mapa una vez en ese enfrentamiento. Despues se
     calma y no vuelve: un aviso que sigue puesto despues de haberle hecho
     caso deja de significar nada, y este panel se repinta solo cada pocos
     segundos -si el latido volviera en cada repintado seria insoportable-.

     La cuenta va en sessionStorage y por enfrentamiento: el siguiente combate
     vuelve a llamar, que es lo que se quiere. Se cierra la pestaña y se
     olvida, que tambien. --}}
<script>
(function () {
    var CLAVE = 'arena:zona-vista:';

    function yaVisto(id) {
        try { return sessionStorage.getItem(CLAVE + id) === '1'; } catch (e) { return false; }
    }

    function darPorVisto(id) {
        try { sessionStorage.setItem(CLAVE + id, '1'); } catch (e) {}
    }

    function pintar(root) {
        (root || document).querySelectorAll('[data-zone-call]').forEach(function (boton) {
            boton.classList.toggle('is-llamando', !yaVisto(boton.dataset.zoneCall));
        });
    }

    // Delegado: el panel se repinta entero con cada cambio de estado, asi que
    // enganchar el boton de turno no sobreviviria a la primera vuelta.
    document.addEventListener('click', function (event) {
        var boton = event.target.closest ? event.target.closest('[data-zone-call]') : null;
        if (!boton) { return; }

        darPorVisto(boton.dataset.zoneCall);
        boton.classList.remove('is-llamando');
    });

    if (window.ArenaBoot) {
        window.ArenaBoot.register(pintar);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { pintar(document); });
    } else {
        pintar(document);
    }
})();
</script>
