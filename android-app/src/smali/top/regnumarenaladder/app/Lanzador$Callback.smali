# El otro extremo de la sesion: Chrome le cuenta aqui lo que pasa (navegacion,
# mensajes). No hace falta nada de eso; solo contestar sin romper.
.class Ltop/regnumarenaladder/app/Lanzador$Callback;
.super Landroid/os/Binder;

.method constructor <init>()V
    .registers 1
    invoke-direct {p0}, Landroid/os/Binder;-><init>()V
    return-void
.end method

.method protected onTransact(ILandroid/os/Parcel;Landroid/os/Parcel;I)Z
    .registers 7
    # INTERFACE_TRANSACTION ('_NTF')
    const v0, 0x5f4e5446
    if-ne p1, v0, :otra
    if-eqz p3, :fin
    const-string v1, "android.support.customtabs.ICustomTabsCallback"
    invoke-virtual {p3, v1}, Landroid/os/Parcel;->writeString(Ljava/lang/String;)V
    goto :fin

    :otra
    if-eqz p3, :fin
    # FLAG_ONEWAY: sin respuesta
    and-int/lit8 v0, p4, 0x1
    if-nez v0, :fin
    invoke-virtual {p3}, Landroid/os/Parcel;->writeNoException()V

    :fin
    const/4 v0, 0x1
    return v0
.end method
