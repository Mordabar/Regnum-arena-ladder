# El servicio de avisos de la app (Trusted Web Activity Service).
#
# Dentro de la app, Chrome no enseña las notificaciones del sitio por su
# cuenta: se las pasa a la app para que salgan con su nombre y su icono, y le
# pregunta a la app si tiene permiso. Sin este servicio la respuesta era "no",
# y en el sitio los avisos salian como bloqueados.
#
# Solo atiende al navegador que abrio la app (Lanzador lo guarda al lanzar).
.class public Ltop/regnumarenaladder/app/Avisos;
.super Landroid/app/Service;

.field binder:Landroid/os/IBinder;

.method public constructor <init>()V
    .registers 1
    invoke-direct {p0}, Landroid/app/Service;-><init>()V
    return-void
.end method

.method public onBind(Landroid/content/Intent;)Landroid/os/IBinder;
    .registers 3
    iget-object v0, p0, Ltop/regnumarenaladder/app/Avisos;->binder:Landroid/os/IBinder;
    if-nez v0, :listo
    new-instance v0, Ltop/regnumarenaladder/app/Avisos$Canal;
    invoke-direct {v0, p0}, Ltop/regnumarenaladder/app/Avisos$Canal;-><init>(Ltop/regnumarenaladder/app/Avisos;)V
    iput-object v0, p0, Ltop/regnumarenaladder/app/Avisos;->binder:Landroid/os/IBinder;
    :listo
    const-string v1, "android-servicio-conectado"
    const-string p1, "Chrome se ha conectado al servicio de avisos"
    invoke-static {v1, p1}, Ltop/regnumarenaladder/app/Informe;->enviar(Ljava/lang/String;Ljava/lang/String;)V
    return-object v0
.end method

.method gestor()Landroid/app/NotificationManager;
    .registers 3
    const-string v0, "notification"
    invoke-virtual {p0, v0}, Landroid/app/Service;->getSystemService(Ljava/lang/String;)Ljava/lang/Object;
    move-result-object v0
    check-cast v0, Landroid/app/NotificationManager;
    return-object v0
.end method

# Si Android deja a la app enseñar notificaciones.
.method habilitadas()Z
    .registers 4
    sget v0, Landroid/os/Build$VERSION;->SDK_INT:I
    const/16 v1, 0x18
    if-lt v0, v1, :antiguo
    invoke-virtual {p0}, Ltop/regnumarenaladder/app/Avisos;->gestor()Landroid/app/NotificationManager;
    move-result-object v1
    invoke-virtual {v1}, Landroid/app/NotificationManager;->areNotificationsEnabled()Z
    move-result v0
    return v0
    :antiguo
    const/4 v0, 0x1
    return v0
.end method

# Que quien llama sea el navegador que abrio la app, y no otra app cualquiera.
.method verificado()Z
    .registers 8
    invoke-static {}, Landroid/os/Binder;->getCallingUid()I
    move-result v0
    invoke-virtual {p0}, Landroid/app/Service;->getPackageManager()Landroid/content/pm/PackageManager;
    move-result-object v1
    invoke-virtual {v1, v0}, Landroid/content/pm/PackageManager;->getPackagesForUid(I)[Ljava/lang/String;
    move-result-object v1
    if-eqz v1, :no
    const-string v2, "twa"
    const/4 v3, 0x0
    invoke-virtual {p0, v2, v3}, Landroid/app/Service;->getSharedPreferences(Ljava/lang/String;I)Landroid/content/SharedPreferences;
    move-result-object v2
    const-string v3, "navegador"
    const/4 v4, 0x0
    invoke-interface {v2, v3, v4}, Landroid/content/SharedPreferences;->getString(Ljava/lang/String;Ljava/lang/String;)Ljava/lang/String;
    move-result-object v2
    if-eqz v2, :no
    array-length v3, v1
    const/4 v4, 0x0
    :bucle
    if-ge v4, v3, :no
    aget-object v5, v1, v4
    invoke-virtual {v2, v5}, Ljava/lang/String;->equals(Ljava/lang/Object;)Z
    move-result v6
    if-nez v6, :si
    add-int/lit8 v4, v4, 0x1
    goto :bucle
    :si
    const/4 v0, 0x1
    return v0
    :no
    const/4 v0, 0x0
    return v0
.end method

.method icono()I
    .registers 5
    invoke-virtual {p0}, Landroid/app/Service;->getResources()Landroid/content/res/Resources;
    move-result-object v0
    const-string v1, "ic_notificacion"
    const-string v2, "drawable"
    invoke-virtual {p0}, Landroid/app/Service;->getPackageName()Ljava/lang/String;
    move-result-object v3
    invoke-virtual {v0, v1, v2, v3}, Landroid/content/res/Resources;->getIdentifier(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)I
    move-result v1
    if-nez v1, :hay
    const-string v1, "ic_launcher"
    const-string v2, "mipmap"
    invoke-virtual {v0, v1, v2, v3}, Landroid/content/res/Resources;->getIdentifier(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)I
    move-result v1
    :hay
    return v1
.end method

# Enseña el aviso que manda Chrome, en el canal de la app.
.method mostrar(Landroid/os/Bundle;)Z
    .registers 12
    if-eqz p1, :no
    invoke-virtual {p0}, Ltop/regnumarenaladder/app/Avisos;->verificado()Z
    move-result v0
    if-nez v0, :verificado
    const-string v0, "android-no-verificado"
    invoke-virtual {p0}, Ltop/regnumarenaladder/app/Avisos;->quienLlama()Ljava/lang/String;
    move-result-object v1
    invoke-static {v0, v1}, Ltop/regnumarenaladder/app/Informe;->enviar(Ljava/lang/String;Ljava/lang/String;)V
    goto :no
    :verificado

    const-string v0, "android.support.customtabs.trusted.PLATFORM_TAG"
    invoke-virtual {p1, v0}, Landroid/os/Bundle;->getString(Ljava/lang/String;)Ljava/lang/String;
    move-result-object v1
    const-string v0, "android.support.customtabs.trusted.PLATFORM_ID"
    invoke-virtual {p1, v0}, Landroid/os/Bundle;->getInt(Ljava/lang/String;)I
    move-result v2
    const-string v0, "android.support.customtabs.trusted.NOTIFICATION"
    invoke-virtual {p1, v0}, Landroid/os/Bundle;->getParcelable(Ljava/lang/String;)Landroid/os/Parcelable;
    move-result-object v3
    check-cast v3, Landroid/app/Notification;
    if-eqz v3, :no
    const-string v0, "android.support.customtabs.trusted.CHANNEL_NAME"
    invoke-virtual {p1, v0}, Landroid/os/Bundle;->getString(Ljava/lang/String;)Ljava/lang/String;
    move-result-object v4
    if-nez v4, :con_nombre
    const-string v4, "Avisos"
    :con_nombre

    invoke-virtual {p0}, Ltop/regnumarenaladder/app/Avisos;->gestor()Landroid/app/NotificationManager;
    move-result-object v5

    sget v0, Landroid/os/Build$VERSION;->SDK_INT:I
    const/16 v6, 0x1a
    if-lt v0, v6, :publicar

    # Android 8+: cada aviso va en un canal. Importancia alta: un cruce
    # caduca en minutos, tiene que asomar arriba y sonar.
    const-string v6, "avisos_arena"
    new-instance v7, Landroid/app/NotificationChannel;
    const/4 v8, 0x4
    invoke-direct {v7, v6, v4, v8}, Landroid/app/NotificationChannel;-><init>(Ljava/lang/String;Ljava/lang/CharSequence;I)V
    invoke-virtual {v5, v7}, Landroid/app/NotificationManager;->createNotificationChannel(Landroid/app/NotificationChannel;)V
    invoke-static {p0, v3}, Landroid/app/Notification$Builder;->recoverBuilder(Landroid/content/Context;Landroid/app/Notification;)Landroid/app/Notification$Builder;
    move-result-object v8
    invoke-virtual {v8, v6}, Landroid/app/Notification$Builder;->setChannelId(Ljava/lang/String;)Landroid/app/Notification$Builder;
    invoke-virtual {v8}, Landroid/app/Notification$Builder;->build()Landroid/app/Notification;
    move-result-object v3

    :publicar
    invoke-virtual {v5, v1, v2, v3}, Landroid/app/NotificationManager;->notify(Ljava/lang/String;ILandroid/app/Notification;)V
    invoke-virtual {p0}, Ltop/regnumarenaladder/app/Avisos;->habilitadas()Z
    move-result v0

    # Para el diagnostico: publicada, con que etiqueta y si Android la deja ver.
    new-instance v6, Ljava/lang/StringBuilder;
    invoke-direct {v6}, Ljava/lang/StringBuilder;-><init>()V
    const-string v7, "tag="
    invoke-virtual {v6, v7}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    invoke-virtual {v6, v1}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    const-string v7, " habilitadas="
    invoke-virtual {v6, v7}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    invoke-virtual {v6, v0}, Ljava/lang/StringBuilder;->append(Z)Ljava/lang/StringBuilder;
    const-string v7, " icono="
    invoke-virtual {v6, v7}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    invoke-virtual {p0}, Ltop/regnumarenaladder/app/Avisos;->icono()I
    move-result v7
    invoke-virtual {v6, v7}, Ljava/lang/StringBuilder;->append(I)Ljava/lang/StringBuilder;
    invoke-virtual {v6}, Ljava/lang/StringBuilder;->toString()Ljava/lang/String;
    move-result-object v6
    const-string v7, "android-aviso-publicado"
    invoke-static {v7, v6}, Ltop/regnumarenaladder/app/Informe;->enviar(Ljava/lang/String;Ljava/lang/String;)V
    return v0

    :no
    const/4 v0, 0x0
    return v0
.end method

.method cancelar(Landroid/os/Bundle;)V
    .registers 6
    if-eqz p1, :fin
    invoke-virtual {p0}, Ltop/regnumarenaladder/app/Avisos;->verificado()Z
    move-result v0
    if-eqz v0, :fin
    const-string v0, "android.support.customtabs.trusted.PLATFORM_TAG"
    invoke-virtual {p1, v0}, Landroid/os/Bundle;->getString(Ljava/lang/String;)Ljava/lang/String;
    move-result-object v1
    const-string v0, "android.support.customtabs.trusted.PLATFORM_ID"
    invoke-virtual {p1, v0}, Landroid/os/Bundle;->getInt(Ljava/lang/String;)I
    move-result v2
    invoke-virtual {p0}, Ltop/regnumarenaladder/app/Avisos;->gestor()Landroid/app/NotificationManager;
    move-result-object v3
    invoke-virtual {v3, v1, v2}, Landroid/app/NotificationManager;->cancel(Ljava/lang/String;I)V
    :fin
    return-void
.end method

.method activas()Landroid/os/Bundle;
    .registers 5
    new-instance v0, Landroid/os/Bundle;
    invoke-direct {v0}, Landroid/os/Bundle;-><init>()V
    sget v1, Landroid/os/Build$VERSION;->SDK_INT:I
    const/16 v2, 0x17
    if-lt v1, v2, :fin
    invoke-virtual {p0}, Ltop/regnumarenaladder/app/Avisos;->gestor()Landroid/app/NotificationManager;
    move-result-object v1
    invoke-virtual {v1}, Landroid/app/NotificationManager;->getActiveNotifications()[Landroid/service/notification/StatusBarNotification;
    move-result-object v1
    const-string v3, "android.support.customtabs.trusted.ACTIVE_NOTIFICATIONS"
    invoke-virtual {v0, v3, v1}, Landroid/os/Bundle;->putParcelableArray(Ljava/lang/String;[Landroid/os/Parcelable;)V
    :fin
    return-object v0
.end method

.method bitmapIcono()Landroid/os/Bundle;
    .registers 5
    new-instance v0, Landroid/os/Bundle;
    invoke-direct {v0}, Landroid/os/Bundle;-><init>()V
    invoke-virtual {p0}, Landroid/app/Service;->getResources()Landroid/content/res/Resources;
    move-result-object v1
    invoke-virtual {p0}, Ltop/regnumarenaladder/app/Avisos;->icono()I
    move-result v2
    invoke-static {v1, v2}, Landroid/graphics/BitmapFactory;->decodeResource(Landroid/content/res/Resources;I)Landroid/graphics/Bitmap;
    move-result-object v1
    const-string v3, "android.support.customtabs.trusted.SMALL_ICON_BITMAP"
    invoke-virtual {v0, v3, v1}, Landroid/os/Bundle;->putParcelable(Ljava/lang/String;Landroid/os/Parcelable;)V
    return-object v0
.end method

# Quien llama y que navegador se guardo, para el informe.
.method quienLlama()Ljava/lang/String;
    .registers 6
    new-instance v0, Ljava/lang/StringBuilder;
    invoke-direct {v0}, Ljava/lang/StringBuilder;-><init>()V
    const-string v1, "llama="
    invoke-virtual {v0, v1}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    invoke-virtual {p0}, Landroid/app/Service;->getPackageManager()Landroid/content/pm/PackageManager;
    move-result-object v1
    invoke-static {}, Landroid/os/Binder;->getCallingUid()I
    move-result v2
    invoke-virtual {v1, v2}, Landroid/content/pm/PackageManager;->getNameForUid(I)Ljava/lang/String;
    move-result-object v1
    invoke-virtual {v0, v1}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    const-string v1, " guardado="
    invoke-virtual {v0, v1}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    const-string v1, "twa"
    const/4 v2, 0x0
    invoke-virtual {p0, v1, v2}, Landroid/app/Service;->getSharedPreferences(Ljava/lang/String;I)Landroid/content/SharedPreferences;
    move-result-object v1
    const-string v2, "navegador"
    const/4 v3, 0x0
    invoke-interface {v1, v2, v3}, Landroid/content/SharedPreferences;->getString(Ljava/lang/String;Ljava/lang/String;)Ljava/lang/String;
    move-result-object v1
    invoke-virtual {v0, v1}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    invoke-virtual {v0}, Ljava/lang/StringBuilder;->toString()Ljava/lang/String;
    move-result-object v0
    return-object v0
.end method
