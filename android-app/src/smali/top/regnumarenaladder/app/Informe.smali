# Le cuenta al servidor lo que pasa dentro de la app, para diagnosticar sin
# tener el movil delante. Va a /avisos/fallo (la misma baliza que usa la web)
# y sale en `php artisan arena:push-check`. En otro hilo: nunca frena a la app.
.class public Ltop/regnumarenaladder/app/Informe;
.super Ljava/lang/Object;
.implements Ljava/lang/Runnable;

.field final causa:Ljava/lang/String;
.field final detalle:Ljava/lang/String;

.method constructor <init>(Ljava/lang/String;Ljava/lang/String;)V
    .registers 3
    invoke-direct {p0}, Ljava/lang/Object;-><init>()V
    iput-object p1, p0, Ltop/regnumarenaladder/app/Informe;->causa:Ljava/lang/String;
    iput-object p2, p0, Ltop/regnumarenaladder/app/Informe;->detalle:Ljava/lang/String;
    return-void
.end method

.method public static enviar(Ljava/lang/String;Ljava/lang/String;)V
    .registers 4
    :try_start
    new-instance v0, Ljava/lang/Thread;
    new-instance v1, Ltop/regnumarenaladder/app/Informe;
    invoke-direct {v1, p0, p1}, Ltop/regnumarenaladder/app/Informe;-><init>(Ljava/lang/String;Ljava/lang/String;)V
    invoke-direct {v0, v1}, Ljava/lang/Thread;-><init>(Ljava/lang/Runnable;)V
    invoke-virtual {v0}, Ljava/lang/Thread;->start()V
    :try_end
    .catch Ljava/lang/Throwable; {:try_start .. :try_end} :fin
    :fin
    return-void
.end method

# Sin comillas ni barras: va dentro de un JSON escrito a mano.
.method static limpio(Ljava/lang/String;)Ljava/lang/String;
    .registers 4
    if-nez p0, :hay
    const-string v0, ""
    return-object v0
    :hay
    const-string v0, "\\"
    const-string v1, "/"
    invoke-virtual {p0, v0, v1}, Ljava/lang/String;->replace(Ljava/lang/CharSequence;Ljava/lang/CharSequence;)Ljava/lang/String;
    move-result-object v2
    const-string v0, "\""
    const-string v1, "'"
    invoke-virtual {v2, v0, v1}, Ljava/lang/String;->replace(Ljava/lang/CharSequence;Ljava/lang/CharSequence;)Ljava/lang/String;
    move-result-object v2
    return-object v2
.end method

.method public run()V
    .registers 8
    :try_start
    new-instance v0, Ljava/net/URL;
    const-string v1, "https://regnumarenaladder.top/avisos/fallo"
    invoke-direct {v0, v1}, Ljava/net/URL;-><init>(Ljava/lang/String;)V
    invoke-virtual {v0}, Ljava/net/URL;->openConnection()Ljava/net/URLConnection;
    move-result-object v0
    check-cast v0, Ljava/net/HttpURLConnection;
    const-string v1, "POST"
    invoke-virtual {v0, v1}, Ljava/net/HttpURLConnection;->setRequestMethod(Ljava/lang/String;)V
    const/4 v1, 0x1
    invoke-virtual {v0, v1}, Ljava/net/HttpURLConnection;->setDoOutput(Z)V
    const/16 v1, 0x1388
    invoke-virtual {v0, v1}, Ljava/net/HttpURLConnection;->setConnectTimeout(I)V
    invoke-virtual {v0, v1}, Ljava/net/HttpURLConnection;->setReadTimeout(I)V
    const-string v1, "Content-Type"
    const-string v2, "application/json"
    invoke-virtual {v0, v1, v2}, Ljava/net/HttpURLConnection;->setRequestProperty(Ljava/lang/String;Ljava/lang/String;)V

    new-instance v1, Ljava/lang/StringBuilder;
    invoke-direct {v1}, Ljava/lang/StringBuilder;-><init>()V
    const-string v2, "{\"causa\":\""
    invoke-virtual {v1, v2}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    iget-object v2, p0, Ltop/regnumarenaladder/app/Informe;->causa:Ljava/lang/String;
    invoke-static {v2}, Ltop/regnumarenaladder/app/Informe;->limpio(Ljava/lang/String;)Ljava/lang/String;
    move-result-object v2
    invoke-virtual {v1, v2}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    const-string v2, "\",\"detalle\":\""
    invoke-virtual {v1, v2}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    iget-object v2, p0, Ltop/regnumarenaladder/app/Informe;->detalle:Ljava/lang/String;
    invoke-static {v2}, Ltop/regnumarenaladder/app/Informe;->limpio(Ljava/lang/String;)Ljava/lang/String;
    move-result-object v2
    invoke-virtual {v1, v2}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    const-string v2, " android="
    invoke-virtual {v1, v2}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    sget v2, Landroid/os/Build$VERSION;->SDK_INT:I
    invoke-virtual {v1, v2}, Ljava/lang/StringBuilder;->append(I)Ljava/lang/StringBuilder;
    const-string v2, " app=1.0.3\",\"permiso\":\"app-android\",\"standalone\":true}"
    invoke-virtual {v1, v2}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    invoke-virtual {v1}, Ljava/lang/StringBuilder;->toString()Ljava/lang/String;
    move-result-object v1

    invoke-virtual {v0}, Ljava/net/HttpURLConnection;->getOutputStream()Ljava/io/OutputStream;
    move-result-object v2
    const-string v3, "UTF-8"
    invoke-virtual {v1, v3}, Ljava/lang/String;->getBytes(Ljava/lang/String;)[B
    move-result-object v1
    invoke-virtual {v2, v1}, Ljava/io/OutputStream;->write([B)V
    invoke-virtual {v2}, Ljava/io/OutputStream;->close()V
    invoke-virtual {v0}, Ljava/net/HttpURLConnection;->getResponseCode()I
    invoke-virtual {v0}, Ljava/net/HttpURLConnection;->disconnect()V
    :try_end
    .catch Ljava/lang/Throwable; {:try_start .. :try_end} :fin
    :fin
    return-void
.end method
