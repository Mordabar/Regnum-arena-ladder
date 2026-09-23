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

    invoke-direct {p0}, Ltop/regnumarenaladder/app/Lanzador;->elegirNavegador()Ljava/lang/String;
    move-result-object v1
    iput-object v1, p0, Ltop/regnumarenaladder/app/Lanzador;->paquete:Ljava/lang/String;
    if-eqz v1, :sin_custom_tabs

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
    invoke-virtual {v0, v2}, Landroid/content/Intent;->putExtras(Landroid/os/Bundle;)Landroid/content/Intent;

    const-string v3, "android.support.customtabs.extra.LAUNCH_AS_TRUSTED_WEB_ACTIVITY"
    const/4 v4, 0x1
    invoke-virtual {v0, v3, v4}, Landroid/content/Intent;->putExtra(Ljava/lang/String;Z)Landroid/content/Intent;

    # El color de la barra de estado y de la de herramientas, el del sitio.
    const-string v3, "android.support.customtabs.extra.TOOLBAR_COLOR"
    const v4, -0xe9eff6
    invoke-virtual {v0, v3, v4}, Landroid/content/Intent;->putExtra(Ljava/lang/String;I)Landroid/content/Intent;
    const-string v3, "androidx.browser.customtabs.extra.NAVIGATION_BAR_COLOR"
    invoke-virtual {v0, v3, v4}, Landroid/content/Intent;->putExtra(Ljava/lang/String;I)Landroid/content/Intent;

    :lanzar
    :try_start
    invoke-virtual {p0, v0}, Landroid/app/Activity;->startActivity(Landroid/content/Intent;)V
    :try_end
    .catch Ljava/lang/Exception; {:try_start .. :try_end} :fallo
    goto :cerrar

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
