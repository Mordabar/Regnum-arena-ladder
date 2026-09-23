# El protocolo con Chrome (ITrustedWebActivityService). Codigos = 1 + el
# numero fijo de cada metodo en el AIDL de androidx.browser:
#   6 areNotificationsEnabled · 2 notifyNotificationWithChannel
#   3 cancelNotification · 4 getSmallIconId · 5 getActiveNotifications
#   7 getSmallIconBitmap · 9 extraCommand
.class Ltop/regnumarenaladder/app/Avisos$Canal;
.super Landroid/os/Binder;

.field final s:Ltop/regnumarenaladder/app/Avisos;

.method constructor <init>(Ltop/regnumarenaladder/app/Avisos;)V
    .registers 2
    invoke-direct {p0}, Landroid/os/Binder;-><init>()V
    iput-object p1, p0, Ltop/regnumarenaladder/app/Avisos$Canal;->s:Ltop/regnumarenaladder/app/Avisos;
    return-void
.end method

.method static leerBundle(Landroid/os/Parcel;)Landroid/os/Bundle;
    .registers 3
    invoke-virtual {p0}, Landroid/os/Parcel;->readInt()I
    move-result v0
    if-eqz v0, :nada
    sget-object v0, Landroid/os/Bundle;->CREATOR:Landroid/os/Parcelable$Creator;
    invoke-interface {v0, p0}, Landroid/os/Parcelable$Creator;->createFromParcel(Landroid/os/Parcel;)Ljava/lang/Object;
    move-result-object v0
    check-cast v0, Landroid/os/Bundle;
    return-object v0
    :nada
    const/4 v0, 0x0
    return-object v0
.end method

.method static escribirBundle(Landroid/os/Parcel;Landroid/os/Bundle;)V
    .registers 4
    invoke-virtual {p0}, Landroid/os/Parcel;->writeNoException()V
    if-nez p1, :con
    const/4 v0, 0x0
    invoke-virtual {p0, v0}, Landroid/os/Parcel;->writeInt(I)V
    return-void
    :con
    const/4 v0, 0x1
    invoke-virtual {p0, v0}, Landroid/os/Parcel;->writeInt(I)V
    invoke-virtual {p1, p0, v0}, Landroid/os/Bundle;->writeToParcel(Landroid/os/Parcel;I)V
    return-void
.end method

.method static escribirResultado(Landroid/os/Parcel;Z)V
    .registers 4
    new-instance v0, Landroid/os/Bundle;
    invoke-direct {v0}, Landroid/os/Bundle;-><init>()V
    const-string v1, "android.support.customtabs.trusted.NOTIFICATION_SUCCESS"
    invoke-virtual {v0, v1, p1}, Landroid/os/Bundle;->putBoolean(Ljava/lang/String;Z)V
    invoke-static {p0, v0}, Ltop/regnumarenaladder/app/Avisos$Canal;->escribirBundle(Landroid/os/Parcel;Landroid/os/Bundle;)V
    return-void
.end method

.method protected onTransact(ILandroid/os/Parcel;Landroid/os/Parcel;I)Z
    .registers 10
    # v0-v4 locales; p0=v5 p1=v6 p2=v7 p3=v8 p4=v9

    const v0, 0x5f4e5446
    if-ne p1, v0, :no_interfaz
    const-string v0, "android.support.customtabs.trusted.ITrustedWebActivityService"
    invoke-virtual {p3, v0}, Landroid/os/Parcel;->writeString(Ljava/lang/String;)V
    const/4 v0, 0x1
    return v0

    :no_interfaz
    const/4 v0, 0x1
    if-lt p1, v0, :otro
    const/16 v0, 0x9
    if-gt p1, v0, :otro

    const-string v0, "android.support.customtabs.trusted.ITrustedWebActivityService"
    invoke-virtual {p2, v0}, Landroid/os/Parcel;->enforceInterface(Ljava/lang/String;)V
    iget-object v1, p0, Ltop/regnumarenaladder/app/Avisos$Canal;->s:Ltop/regnumarenaladder/app/Avisos;

    const/4 v2, 0x6
    if-eq p1, v2, :habilitadas
    const/4 v2, 0x2
    if-eq p1, v2, :notificar
    const/4 v2, 0x3
    if-eq p1, v2, :cancelar
    const/4 v2, 0x4
    if-eq p1, v2, :icono
    const/4 v2, 0x5
    if-eq p1, v2, :activas
    const/4 v2, 0x7
    if-eq p1, v2, :bitmap
    const/16 v2, 0x9
    if-eq p1, v2, :extra

    :otro
    invoke-super {p0, p1, p2, p3, p4}, Landroid/os/Binder;->onTransact(ILandroid/os/Parcel;Landroid/os/Parcel;I)Z
    move-result v0
    return v0

    :habilitadas
    invoke-static {p2}, Ltop/regnumarenaladder/app/Avisos$Canal;->leerBundle(Landroid/os/Parcel;)Landroid/os/Bundle;
    invoke-virtual {v1}, Ltop/regnumarenaladder/app/Avisos;->habilitadas()Z
    move-result v3
    invoke-static {p3, v3}, Ltop/regnumarenaladder/app/Avisos$Canal;->escribirResultado(Landroid/os/Parcel;Z)V
    goto :hecho

    :notificar
    invoke-static {p2}, Ltop/regnumarenaladder/app/Avisos$Canal;->leerBundle(Landroid/os/Parcel;)Landroid/os/Bundle;
    move-result-object v4
    invoke-virtual {v1, v4}, Ltop/regnumarenaladder/app/Avisos;->mostrar(Landroid/os/Bundle;)Z
    move-result v3
    invoke-static {p3, v3}, Ltop/regnumarenaladder/app/Avisos$Canal;->escribirResultado(Landroid/os/Parcel;Z)V
    goto :hecho

    :cancelar
    invoke-static {p2}, Ltop/regnumarenaladder/app/Avisos$Canal;->leerBundle(Landroid/os/Parcel;)Landroid/os/Bundle;
    move-result-object v4
    invoke-virtual {v1, v4}, Ltop/regnumarenaladder/app/Avisos;->cancelar(Landroid/os/Bundle;)V
    invoke-virtual {p3}, Landroid/os/Parcel;->writeNoException()V
    goto :hecho

    :icono
    invoke-virtual {v1}, Ltop/regnumarenaladder/app/Avisos;->icono()I
    move-result v3
    invoke-virtual {p3}, Landroid/os/Parcel;->writeNoException()V
    invoke-virtual {p3, v3}, Landroid/os/Parcel;->writeInt(I)V
    goto :hecho

    :activas
    invoke-virtual {v1}, Ltop/regnumarenaladder/app/Avisos;->activas()Landroid/os/Bundle;
    move-result-object v4
    invoke-static {p3, v4}, Ltop/regnumarenaladder/app/Avisos$Canal;->escribirBundle(Landroid/os/Parcel;Landroid/os/Bundle;)V
    goto :hecho

    :bitmap
    invoke-virtual {v1}, Ltop/regnumarenaladder/app/Avisos;->bitmapIcono()Landroid/os/Bundle;
    move-result-object v4
    invoke-static {p3, v4}, Ltop/regnumarenaladder/app/Avisos$Canal;->escribirBundle(Landroid/os/Parcel;Landroid/os/Bundle;)V
    goto :hecho

    :extra
    # Ordenes extra que esta app no usa: se contesta "nada".
    const/4 v4, 0x0
    invoke-static {p3, v4}, Ltop/regnumarenaladder/app/Avisos$Canal;->escribirBundle(Landroid/os/Parcel;Landroid/os/Bundle;)V

    :hecho
    const/4 v0, 0x1
    return v0
.end method
