# El lanzador de la app. Abre regnumarenaladder.top en Chrome como Trusted Web
# Activity: a pantalla completa, sin barra de direcciones, y con los avisos del
# sitio funcionando porque quien lo pinta es Chrome.
#
# 1. Busca un navegador compatible (Chrome primero).
# 2. Se conecta a su servicio de Custom Tabs y abre una sesion: es lo que
#    identifica a esta app ante Chrome, que comprueba en
#    /.well-known/assetlinks.json que el sitio la reconoce.
# 3. Lanza el sitio en modo TWA con esa sesion y se cierra.
# Si algo falla, abre el sitio en el navegador normal: nunca se queda en blanco.
.class public Ltop/regnumarenaladder/app/Lanzador;
.super Landroid/app/Activity;

.field conexion:Landroid/content/ServiceConnection;
.field url:Landroid/net/Uri;
.field paquete:Ljava/lang/String;
.field abierto:Z
# El id de la sesion para Chrome (androidx.browser). Sin el, Chrome tira la
# sesion en cuanto el proceso de la app se muere.
.field sesionId:Landroid/app/PendingIntent;

.method public constructor <init>()V
    .registers 1
    invoke-direct {p0}, Landroid/app/Activity;-><init>()V
    return-void
.end method

.method protected onCreate(Landroid/os/Bundle;)V
    .registers 7

    invoke-super {p0, p1}, Landroid/app/Activity;->onCreate(Landroid/os/Bundle;)V

    # La direccion: la del enlace que abrio la app, o el lobby.
    invoke-virtual {p0}, Landroid/app/Activity;->getIntent()Landroid/content/Intent;
    move-result-object v0
    invoke-virtual {v0}, Landroid/content/Intent;->getData()Landroid/net/Uri;
    move-result-object v0
    if-nez v0, :tiene_url
    const-string v1, "https://regnumarenaladder.top/lobby"
    invoke-static {v1}, Landroid/net/Uri;->parse(Ljava/lang/String;)Landroid/net/Uri;
    move-result-object v0
    :tiene_url
    iput-object v0, p0, Ltop/regnumarenaladder/app/Lanzador;->url:Landroid/net/Uri;

    # Android 13+: el permiso de notificaciones lo tiene que pedir la app. Sin
    # el, Chrome ve los avisos del sitio como bloqueados.
    sget v1, Landroid/os/Build$VERSION;->SDK_INT:I
    const/16 v2, 0x21
    if-lt v1, v2, :seguir
    const-string v1, "android.permission.POST_NOTIFICATIONS"
    invoke-virtual {p0, v1}, Landroid/app/Activity;->checkSelfPermission(Ljava/lang/String;)I
    move-result v2
    if-eqz v2, :seguir
    const/4 v2, 0x1
    new-array v2, v2, [Ljava/lang/String;
    const/4 v3, 0x0
    aput-object v1, v2, v3
    const/4 v3, 0x7
    invoke-virtual {p0, v2, v3}, Landroid/app/Activity;->requestPermissions([Ljava/lang/String;I)V
    return-void

    :seguir
    invoke-virtual {p0}, Ltop/regnumarenaladder/app/Lanzador;->continuar()V
    return-void
.end method

# Lo que diga la persona al permiso, se sigue: con o sin avisos, la app abre.
.method public onRequestPermissionsResult(I[Ljava/lang/String;[I)V
    .registers 4
    invoke-virtual {p0}, Ltop/regnumarenaladder/app/Lanzador;->continuar()V
    return-void
.end method

.method continuar()V
    .registers 7

    invoke-direct {p0}, Ltop/regnumarenaladder/app/Lanzador;->elegirNavegador()Ljava/lang/String;
    move-result-object v1
    iput-object v1, p0, Ltop/regnumarenaladder/app/Lanzador;->paquete:Ljava/lang/String;
    if-eqz v1, :sin_custom_tabs

    new-instance v2, Landroid/content/Intent;
    invoke-direct {v2}, Landroid/content/Intent;-><init>()V
    invoke-virtual {p0}, Landroid/app/Activity;->getPackageName()Ljava/lang/String;
    move-result-object v3
    invoke-virtual {v2, v3}, Landroid/content/Intent;->setPackage(Ljava/lang/String;)Landroid/content/Intent;
    const/4 v3, 0x0
    # FLAG_IMMUTABLE
    const/high16 v4, 0x4000000
    invoke-static {p0, v3, v2, v4}, Landroid/app/PendingIntent;->getActivity(Landroid/content/Context;ILandroid/content/Intent;I)Landroid/app/PendingIntent;
    move-result-object v2
    iput-object v2, p0, Ltop/regnumarenaladder/app/Lanzador;->sesionId:Landroid/app/PendingIntent;

    # Se apunta que navegador abre la app: el servicio de avisos solo le hace
    # caso a ese.
    const-string v2, "twa"
    const/4 v3, 0x0
    invoke-virtual {p0, v2, v3}, Landroid/app/Activity;->getSharedPreferences(Ljava/lang/String;I)Landroid/content/SharedPreferences;
    move-result-object v2
    invoke-interface {v2}, Landroid/content/SharedPreferences;->edit()Landroid/content/SharedPreferences$Editor;
    move-result-object v2
    const-string v3, "navegador"
    invoke-interface {v2, v3, v1}, Landroid/content/SharedPreferences$Editor;->putString(Ljava/lang/String;Ljava/lang/String;)Landroid/content/SharedPreferences$Editor;
    invoke-interface {v2}, Landroid/content/SharedPreferences$Editor;->apply()V

    :try_start
    new-instance v2, Landroid/content/Intent;
    const-string v3, "android.support.customtabs.action.CustomTabsService"
    invoke-direct {v2, v3}, Landroid/content/Intent;-><init>(Ljava/lang/String;)V
    invoke-virtual {v2, v1}, Landroid/content/Intent;->setPackage(Ljava/lang/String;)Landroid/content/Intent;

    new-instance v3, Ltop/regnumarenaladder/app/Lanzador$Conexion;
    invoke-direct {v3, p0}, Ltop/regnumarenaladder/app/Lanzador$Conexion;-><init>(Ltop/regnumarenaladder/app/Lanzador;)V
    iput-object v3, p0, Ltop/regnumarenaladder/app/Lanzador;->conexion:Landroid/content/ServiceConnection;

    # BIND_AUTO_CREATE | BIND_WAIVE_PRIORITY
    const/16 v4, 0x21
    invoke-virtual {p0, v2, v3, v4}, Landroid/app/Activity;->bindService(Landroid/content/Intent;Landroid/content/ServiceConnection;I)Z
    move-result v4
    :try_end
    .catch Ljava/lang/Exception; {:try_start .. :try_end} :sin_custom_tabs

    if-eqz v4, :sin_custom_tabs
    return-void

    :sin_custom_tabs
    const/4 v2, 0x0
    invoke-virtual {p0, v2}, Ltop/regnumarenaladder/app/Lanzador;->abrir(Landroid/os/IBinder;)V
    return-void
.end method

# El navegador que abre el sitio: Chrome si esta; si no, el primero que sepa
# hacer Custom Tabs (Edge, Samsung Internet, Brave...). Null si no hay ninguno.
.method private elegirNavegador()Ljava/lang/String;
    .registers 7

    invoke-virtual {p0}, Landroid/app/Activity;->getPackageManager()Landroid/content/pm/PackageManager;
    move-result-object v0
    new-instance v1, Landroid/content/Intent;
    const-string v2, "android.support.customtabs.action.CustomTabsService"
    invoke-direct {v1, v2}, Landroid/content/Intent;-><init>(Ljava/lang/String;)V
    const/4 v2, 0x0
    invoke-virtual {v0, v1, v2}, Landroid/content/pm/PackageManager;->queryIntentServices(Landroid/content/Intent;I)Ljava/util/List;
    move-result-object v1
    const/4 v3, 0x0
    if-eqz v1, :fin

    :bucle
    invoke-interface {v1}, Ljava/util/List;->size()I
    move-result v4
    if-ge v2, v4, :fin
    invoke-interface {v1, v2}, Ljava/util/List;->get(I)Ljava/lang/Object;
    move-result-object v4
    check-cast v4, Landroid/content/pm/ResolveInfo;
    iget-object v4, v4, Landroid/content/pm/ResolveInfo;->serviceInfo:Landroid/content/pm/ServiceInfo;
    iget-object v4, v4, Landroid/content/pm/ServiceInfo;->packageName:Ljava/lang/String;
    const-string v5, "com.android.chrome"
    invoke-virtual {v5, v4}, Ljava/lang/String;->equals(Ljava/lang/Object;)Z
    move-result v5
    if-eqz v5, :no_es_chrome
    return-object v4
    :no_es_chrome
    if-nez v3, :siguiente
    move-object v3, v4
    :siguiente
    add-int/lit8 v2, v2, 0x1
    goto :bucle

    :fin
    return-object v3
.end method

# Abre el sitio. Con sesion, como app a pantalla completa; sin ella, en el
# navegador de siempre. Solo una vez aunque llegue dos veces.
.method abrir(Landroid/os/IBinder;)V
    .registers 7

    iget-boolean v0, p0, Ltop/regnumarenaladder/app/Lanzador;->abierto:Z
    if-eqz v0, :primera_vez
    return-void
    :primera_vez
    const/4 v0, 0x1
    iput-boolean v0, p0, Ltop/regnumarenaladder/app/Lanzador;->abierto:Z

    new-instance v0, Landroid/content/Intent;
    const-string v1, "android.intent.action.VIEW"
    iget-object v2, p0, Ltop/regnumarenaladder/app/Lanzador;->url:Landroid/net/Uri;
    invoke-direct {v0, v1, v2}, Landroid/content/Intent;-><init>(Ljava/lang/String;Landroid/net/Uri;)V

    iget-object v1, p0, Ltop/regnumarenaladder/app/Lanzador;->paquete:Ljava/lang/String;
    if-eqz v1, :lanzar
    invoke-virtual {v0, v1}, Landroid/content/Intent;->setPackage(Ljava/lang/String;)Landroid/content/Intent;

    # La sesion va SIEMPRE como extra, aunque sea nula: es lo que convierte el
    # intent en uno de Custom Tabs.
    new-instance v2, Landroid/os/Bundle;
    invoke-direct {v2}, Landroid/os/Bundle;-><init>()V
    const-string v3, "android.support.customtabs.extra.SESSION"
    invoke-virtual {v2, v3, p1}, Landroid/os/Bundle;->putBinder(Ljava/lang/String;Landroid/os/IBinder;)V
    iget-object v3, p0, Ltop/regnumarenaladder/app/Lanzador;->sesionId:Landroid/app/PendingIntent;
    if-eqz v3, :sin_id
    const-string v4, "android.support.customtabs.extra.SESSION_ID"
    invoke-virtual {v2, v4, v3}, Landroid/os/Bundle;->putParcelable(Ljava/lang/String;Landroid/os/Parcelable;)V
    :sin_id
    invoke-virtual {v0, v2}, Landroid/content/Intent;->putExtras(Landroid/os/Bundle;)Landroid/content/Intent;

    # Modo app solo CON sesion: sin ella Chrome no puede verificarla y la
    # abriria igual como pestaña con barra.
    if-eqz p1, :sin_twa
    const-string v3, "android.support.customtabs.extra.LAUNCH_AS_TRUSTED_WEB_ACTIVITY"
    const/4 v4, 0x1
    invoke-virtual {v0, v3, v4}, Landroid/content/Intent;->putExtra(Ljava/lang/String;Z)Landroid/content/Intent;
    :sin_twa

    # El color de la barra de estado y de la de herramientas, el del sitio.
    const-string v3, "android.support.customtabs.extra.TOOLBAR_COLOR"
    const v4, -0xe9eff6
    invoke-virtual {v0, v3, v4}, Landroid/content/Intent;->putExtra(Ljava/lang/String;I)Landroid/content/Intent;
    const-string v3, "androidx.browser.customtabs.extra.NAVIGATION_BAR_COLOR"
    invoke-virtual {v0, v3, v4}, Landroid/content/Intent;->putExtra(Ljava/lang/String;I)Landroid/content/Intent;

    :lanzar
    new-instance v2, Ljava/lang/StringBuilder;
    invoke-direct {v2}, Ljava/lang/StringBuilder;-><init>()V
    const-string v3, "navegador="
    invoke-virtual {v2, v3}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    iget-object v3, p0, Ltop/regnumarenaladder/app/Lanzador;->paquete:Ljava/lang/String;
    invoke-virtual {v2, v3}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    const-string v3, " sesion="
    invoke-virtual {v2, v3}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    if-eqz p1, :sin_sesion
    const/4 v3, 0x1
    goto :con_sesion
    :sin_sesion
    const/4 v3, 0x0
    :con_sesion
    invoke-virtual {v2, v3}, Ljava/lang/StringBuilder;->append(Z)Ljava/lang/StringBuilder;
    invoke-virtual {v2}, Ljava/lang/StringBuilder;->toString()Ljava/lang/String;
    move-result-object v2
    const-string v3, "android-lanzador"
    invoke-static {v3, v2}, Ltop/regnumarenaladder/app/Informe;->enviar(Ljava/lang/String;Ljava/lang/String;)V

    :try_start
    invoke-virtual {p0, v0}, Landroid/app/Activity;->startActivity(Landroid/content/Intent;)V
    :try_end
    .catch Ljava/lang/Exception; {:try_start .. :try_end} :fallo
    # Con sesion, el lanzador no se cierra ya: mantiene viva la conexion con
    # Chrome mientras la app esta abierta, como hace el lanzador oficial.
    # Se cierra al volver a el (onRestart).
    if-eqz p1, :cerrar
    return-void

    :fallo
    :try_start2
    new-instance v0, Landroid/content/Intent;
    const-string v1, "android.intent.action.VIEW"
    iget-object v2, p0, Ltop/regnumarenaladder/app/Lanzador;->url:Landroid/net/Uri;
    invoke-direct {v0, v1, v2}, Landroid/content/Intent;-><init>(Ljava/lang/String;Landroid/net/Uri;)V
    invoke-virtual {p0, v0}, Landroid/app/Activity;->startActivity(Landroid/content/Intent;)V
    :try_end2
    .catch Ljava/lang/Exception; {:try_start2 .. :try_end2} :cerrar

    :cerrar
    invoke-virtual {p0}, Landroid/app/Activity;->finish()V
    return-void
.end method

.method protected onRestart()V
    .registers 2
    invoke-super {p0}, Landroid/app/Activity;->onRestart()V
    iget-boolean v0, p0, Ltop/regnumarenaladder/app/Lanzador;->abierto:Z
    if-eqz v0, :fin
    invoke-virtual {p0}, Landroid/app/Activity;->finish()V
    :fin
    return-void
.end method

.method protected onDestroy()V
    .registers 3
    iget-object v0, p0, Ltop/regnumarenaladder/app/Lanzador;->conexion:Landroid/content/ServiceConnection;
    if-eqz v0, :fin
    :try_start
    invoke-virtual {p0, v0}, Landroid/app/Activity;->unbindService(Landroid/content/ServiceConnection;)V
    :try_end
    .catch Ljava/lang/Exception; {:try_start .. :try_end} :fin
    :fin
    invoke-super {p0}, Landroid/app/Activity;->onDestroy()V
    return-void
.end method
